<?php

namespace Illuminate\Support;

use BadMethodCallException;
use Illuminate\Console\Events\ArtisanStarting;

abstract class ServiceProvider
{
    /**
     * The application instance.
     * 应用实例
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * Indicates if loading of the provider is deferred.
     * 标示加载的提供者是否延期
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * The paths that should be published.
     * 需要发布的路径
     *
     * @var array
     */
    protected static $publishes = [];

    /**
     * The paths that should be published by group.
     * 需要分组发布的路径
     *
     * @var array
     */
    protected static $publishGroups = [];

    /**
     * Create a new service provider instance.
     * 创建一个新的服务提供实例
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return void
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Register the service provider.
     * 注册服务提供者
     *
     * @return void
     */
    abstract public function register();

    /**
     * Merge the given configuration with the existing configuration.
     * 使用存在的参数合并给定参数
     *
     * @param  string  $path
     * @param  string  $key
     * @return void
     */
    protected function mergeConfigFrom($path, $key)
    {
        $config = $this->app['config']->get($key, []);

        $this->app['config']->set($key, array_merge(require $path, $config));
    }

    /**
     * Register a view file namespace.
     * 注册一个文件视图命名空间
     *
     * @param  string  $path
     * @param  string  $namespace
     * @return void
     */
    protected function loadViewsFrom($path, $namespace)
    {
        if (is_dir($appPath = $this->app->basePath().'/resources/views/vendor/'.$namespace)) {
            $this->app['view']->addNamespace($namespace, $appPath);
        }

        $this->app['view']->addNamespace($namespace, $path);
    }

    /**
     * Register a translation file namespace.
     * 注册一个翻译文件的命名空间
     *
     * @param  string  $path
     * @param  string  $namespace
     * @return void
     */
    protected function loadTranslationsFrom($path, $namespace)
    {
        $this->app['translator']->addNamespace($namespace, $path);
    }

    /**
     * Register paths to be published by the publish command.
     * 注册通过发布命名发布的路径
     *
     * @param  array  $paths
     * @param  string  $group
     * @return void
     */
    protected function publishes(array $paths, $group = null)
    {
        $class = static::class;

        if (! array_key_exists($class, static::$publishes)) {
            static::$publishes[$class] = [];
        }

        static::$publishes[$class] = array_merge(static::$publishes[$class], $paths);

        if ($group) {
            if (! array_key_exists($group, static::$publishGroups)) {
                static::$publishGroups[$group] = [];
            }

            static::$publishGroups[$group] = array_merge(static::$publishGroups[$group], $paths);
        }
    }

    /**
     * Get the paths to publish.
     * 获取需要发布的路径
     *
     * @param  string  $provider
     * @param  string  $group
     * @return array
     */
    public static function pathsToPublish($provider = null, $group = null)
    {
        if ($provider && $group) {
            if (empty(static::$publishes[$provider]) || empty(static::$publishGroups[$group])) {
                return [];
            }

            return array_intersect_key(static::$publishes[$provider], static::$publishGroups[$group]);
        }

        if ($group && array_key_exists($group, static::$publishGroups)) {
            return static::$publishGroups[$group];
        }

        if ($provider && array_key_exists($provider, static::$publishes)) {
            return static::$publishes[$provider];
        }

        if ($group || $provider) {
            return [];
        }

        $paths = [];

        foreach (static::$publishes as $class => $publish) {
            $paths = array_merge($paths, $publish);
        }

        return $paths;
    }

    /**
     * Register the package's custom Artisan commands.
     * 注册包的自定义
     *
     * @param  array|mixed  $commands
     * @return void
     */
    public function commands($commands)
    {
        $commands = is_array($commands) ? $commands : func_get_args();

        // To register the commands with Artisan, we will grab each of the arguments
        // passed into the method and listen for Artisan "start" event which will
        // give us the Artisan console instance which we will give commands to.
        $events = $this->app['events'];

        $events->listen(ArtisanStarting::class, function ($event) use ($commands) {
            $event->artisan->resolveCommands($commands);
        });
    }

    /**
     * Get the services provided by the provider.
     * 获取提供者提供的服务
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    /**
     * Get the events that trigger this service provider to register.
     * 获取事件触发服务提供者来注册
     *
     * @return array
     */
    public function when()
    {
        return [];
    }

    /**
     * Determine if the provider is deferred.
     * 判断提供者是否延期
     *
     * @return bool
     */
    public function isDeferred()
    {
        return $this->defer;
    }

    /**
     * Get a list of files that should be compiled for the package.
     * 获取应该被编译成包的文件列表
     *
     * @return array
     */
    public static function compiles()
    {
        return [];
    }

    /**
     * Dynamically handle missing method calls.
     * 缺省方法调用
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     *
     * @throws \BadMethodCallException
     */
    public function __call($method, $parameters)
    {
        if ($method == 'boot') {
            return;
        }

        throw new BadMethodCallException("Call to undefined method [{$method}]");
    }
}
