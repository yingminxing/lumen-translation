<?php

namespace Illuminate\Database;

use PDO;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Database\Connectors\ConnectionFactory;

class DatabaseManager implements ConnectionResolverInterface
{
    /**
     * The application instance.
     * 应用实例
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * The database connection factory instance.
     * 数据库连接工厂实例
     *
     * @var \Illuminate\Database\Connectors\ConnectionFactory
     */
    protected $factory;

    /**
     * The active connection instances.
     * 当前活跃连接实例数组
     *
     * @var array
     */
    protected $connections = [];

    /**
     * The custom connection resolvers.
     * 驱动扩展(类似于oracle,sql server)
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * Create a new database manager instance.
     * 创建一个新的数据库维护实例
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @param  \Illuminate\Database\Connectors\ConnectionFactory  $factory
     * @return void
     */
    public function __construct($app, ConnectionFactory $factory)
    {
        $this->app = $app;
        $this->factory = $factory;
    }

    /**
     * Get a database connection instance.
     * 获得一个数据库连接实例
     *
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    public function connection($name = null)
    {
        list($name, $type) = $this->parseConnectionName($name);

        // If we haven't created this connection, we'll create it based on the config
        // provided in the application. Once we've created the connections we will
        // set the "fetch mode" for PDO which determines the query return types.
        // 如果没有创建连接,则根据数据库实例提供的配置参数生成连接.
        // 一旦我们创建了连接,我们将根据类型设置pdo类型,同时根据参数fetch模式返回数据类型,同时将连接重新链接
        if (! isset($this->connections[$name])) {
            $connection = $this->makeConnection($name);

            $this->setPdoForType($connection, $type);

            $this->connections[$name] = $this->prepare($connection);
        }

        return $this->connections[$name];
    }

    /**
     * Parse the connection into an array of the name and read / write type.
     * 解析连接的名字和读写类型(name::read)到数组中
     *
     * @param  string  $name
     * @return array
     */
    protected function parseConnectionName($name)
    {
        $name = $name ?: $this->getDefaultConnection();

        return Str::endsWith($name, ['::read', '::write'])
                            ? explode('::', $name, 2) : [$name, null];
    }

    /**
     * Disconnect from the given database and remove from local cache.
     * 将给定数据库断开并从本地连接数组中移除
     *
     * @param  string  $name
     * @return void
     */
    public function purge($name = null)
    {
        $this->disconnect($name);

        unset($this->connections[$name]);
    }

    /**
     * Disconnect from the given database.
     *
     * @param  string  $name
     * @return void
     */
    public function disconnect($name = null)
    {
        if (isset($this->connections[$name = $name ?: $this->getDefaultConnection()])) {
            $this->connections[$name]->disconnect();
        }
    }

    /**
     * Reconnect to the given database.
     * 重新连接指定数据库
     *
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    public function reconnect($name = null)
    {
        $this->disconnect($name = $name ?: $this->getDefaultConnection());

        if (! isset($this->connections[$name])) {
            return $this->connection($name);
        }

        return $this->refreshPdoConnections($name);
    }

    /**
     * Refresh the PDO connections on a given connection.
     * 使用连接名称重刷PDO连接(实例重连,设置实例的读写PDO)
     *
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    protected function refreshPdoConnections($name)
    {
        $fresh = $this->makeConnection($name);

        return $this->connections[$name]
                                ->setPdo($fresh->getPdo())
                                ->setReadPdo($fresh->getReadPdo());
    }

    /**
     * Make the database connection instance.
     * 生成数据库连接实例
     *
     * @param  string  $name
     * @return \Illuminate\Database\Connection
     */
    protected function makeConnection($name)
    {
        $config = $this->getConfig($name);

        // First we will check by the connection name to see if an extension has been
        // registered specifically for that connection. If it has we will call the
        // Closure and pass it the config allowing it to resolve the connection.
        //
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }

        $driver = $config['driver'];

        // Next we will check to see if an extension has been registered for a driver
        // and will call the Closure if so, which allows us to have a more generic
        // resolver for the drivers themselves which applies to all connections.
        // ???????
        if (isset($this->extensions[$driver])) {
            return call_user_func($this->extensions[$driver], $config, $name);
        }

        return $this->factory->make($config, $name);
    }

    /**
     * Prepare the database connection instance.
     * 准备数据库连接实例
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return \Illuminate\Database\Connection
     */
    protected function prepare(Connection $connection)
    {
        $connection->setFetchMode($this->app['config']['database.fetch']);

        // 若容器实例绑定了事件,则将事件分发实例绑定到连接上
        if ($this->app->bound('events')) {
            $connection->setEventDispatcher($this->app['events']);
        }

        // Here we'll set a reconnector callback. This reconnector can be any callable
        // so we will set a Closure to reconnect from this manager with the name of
        // the connection, which will allow us to reconnect from the connections.
        // 使用连接实例进行连接实例的重连回调
        $connection->setReconnector(function ($connection) {
            $this->reconnect($connection->getName());
        });

        return $connection;
    }

    /**
     * Prepare the read write mode for database connection instance.
     * 根据类型为数据库连接实例准备读写模式
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $type
     * @return \Illuminate\Database\Connection
     */
    protected function setPdoForType(Connection $connection, $type = null)
    {
        if ($type == 'read') {
            $connection->setPdo($connection->getReadPdo());
        } elseif ($type == 'write') {
            $connection->setReadPdo($connection->getPdo());
        }

        return $connection;
    }

    /**
     * Get the configuration for a connection.
     * 获取连接的参数
     *
     * @param  string  $name
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function getConfig($name)
    {
        $name = $name ?: $this->getDefaultConnection();

        // To get the database connection configuration, we will just pull each of the
        // connection configurations and get the configurations for the given name.
        // If the configuration doesn't exist, we'll throw an exception and bail.
        // 为了得到连接参数,我们从数据库参数连接中根据传入名称获取对应的配置,如果未找到对应的参数配置则抛出异常
        $connections = $this->app['config']['database.connections'];

        if (is_null($config = Arr::get($connections, $name))) {
            throw new InvalidArgumentException("Database [$name] not configured.");
        }

        return $config;
    }

    /**
     * Get the default connection name.
     * 获取默认的连接名称
     *
     * @return string
     */
    public function getDefaultConnection()
    {
        return $this->app['config']['database.default'];
    }

    /**
     * Set the default connection name.
     * 设置默认连接名称
     *
     * @param  string  $name
     * @return void
     */
    public function setDefaultConnection($name)
    {
        $this->app['config']['database.default'] = $name;
    }

    /**
     * Get all of the support drivers.
     * 获取所有支持的驱动(只支持四种'mysql', 'pgsql', 'sqlite', 'sqlsrv')
     *
     * @return array
     */
    public function supportedDrivers()
    {
        return ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];
    }

    /**
     * Get all of the drivers that are actually available.
     * 获取所有数组的交集
     *
     * @return array
     */
    public function availableDrivers()
    {
        return array_intersect($this->supportedDrivers(), str_replace('dblib', 'sqlsrv', PDO::getAvailableDrivers()));
    }

    /**
     * Register an extension connection resolver.
     * 注册一个扩展连接的分解器
     *
     * @param  string    $name
     * @param  callable  $resolver
     * @return void
     */
    public function extend($name, callable $resolver)
    {
        $this->extensions[$name] = $resolver;
    }

    /**
     * Return all of the created connections.
     * 返回所有已创建的连接
     *
     * @return array
     */
    public function getConnections()
    {
        return $this->connections;
    }

    /**
     * Dynamically pass methods to the default connection.
     * 动态将方法传给默认的连接
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array([$this->connection(), $method], $parameters);
    }
}
