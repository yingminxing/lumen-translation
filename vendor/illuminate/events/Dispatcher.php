<?php

namespace Illuminate\Events;

use Exception;
use ReflectionClass;
use Illuminate\Support\Str;
use Illuminate\Container\Container;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Container\Container as ContainerContract;

class Dispatcher implements DispatcherContract
{
    /**
     * The IoC container instance.
     * IOC容器实例
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The registered event listeners.
     * 已注册事件监听者
     *
     * @var array
     */
    protected $listeners = [];

    /**
     * The wildcard listeners.
     * 匹配所有的监听者
     *
     * @var array
     */
    protected $wildcards = [];

    /**
     * The sorted event listeners.
     * 已排序的事件监听者
     *
     * @var array
     */
    protected $sorted = [];

    /**
     * The event firing stack.
     * 事件通知栈
     *
     * @var array
     */
    protected $firing = [];

    /**
     * The queue resolver instance.
     * 队列解决实例
     *
     * @var callable
     */
    protected $queueResolver;

    /**
     * Create a new event dispatcher instance.
     * 创建一个新的事件分发实例
     *
     * @param  \Illuminate\Contracts\Container\Container|null  $container
     * @return void
     */
    public function __construct(ContainerContract $container = null)
    {
        $this->container = $container ?: new Container;
    }

    /**
     * Register an event listener with the dispatcher.
     * 使用分发器注册一个事件监听者
     *
     * @param  string|array  $events
     * @param  mixed  $listener
     * @param  int  $priority
     * @return void
     */
    public function listen($events, $listener, $priority = 0)
    {
        foreach ((array) $events as $event) {
            if (Str::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][$priority][] = $this->makeListener($listener);

                unset($this->sorted[$event]);
            }
        }
    }

    /**
     * Setup a wildcard listener callback.
     * 设置一个全局监听者
     *
     * @param  string  $event
     * @param  mixed  $listener
     * @return void
     */
    protected function setupWildcardListen($event, $listener)
    {
        $this->wildcards[$event][] = $this->makeListener($listener);
    }

    /**
     * Determine if a given event has listeners.
     * 判断给定事件是否含有监听者
     *
     * @param  string  $eventName
     * @return bool
     */
    public function hasListeners($eventName)
    {
        return isset($this->listeners[$eventName]) || isset($this->wildcards[$eventName]);
    }

    /**
     * Register an event and payload to be fired later.
     * 注册一个事件然后延迟通知(event_pushed)
     *
     * @param  string  $event
     * @param  array  $payload
     * @return void
     */
    public function push($event, $payload = [])
    {
        $this->listen($event.'_pushed', function () use ($event, $payload) {
            $this->fire($event, $payload);
        });
    }

    /**
     * Register an event subscriber with the dispatcher.
     * 使用分发器注册一个事件订阅者
     *
     * @param  object|string  $subscriber
     * @return void
     */
    public function subscribe($subscriber)
    {
        $subscriber = $this->resolveSubscriber($subscriber);

        $subscriber->subscribe($this);
    }

    /**
     * Resolve the subscriber instance.
     * 获取一个订阅实例
     *
     * @param  object|string  $subscriber
     * @return mixed
     */
    protected function resolveSubscriber($subscriber)
    {
        if (is_string($subscriber)) {
            return $this->container->make($subscriber);
        }

        return $subscriber;
    }

    /**
     * Fire an event until the first non-null response is returned.
     * 通知事件直到非null反馈返回
     *
     * @param  string|object  $event
     * @param  array  $payload
     * @return mixed
     */
    public function until($event, $payload = [])
    {
        return $this->fire($event, $payload, true);
    }

    /**
     * Flush a set of pushed events.
     * 刷新需要发布事件的集合
     *
     * @param  string  $event
     * @return void
     */
    public function flush($event)
    {
        $this->fire($event.'_pushed');
    }

    /**
     * Get the event that is currently firing.
     * 获取正在通知的事件
     *
     * @return string
     */
    public function firing()
    {
        return last($this->firing);
    }

    /**
     * Fire an event and call the listeners.
     * 通知一个事件,然后调用监听事件
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    public function fire($event, $payload = [], $halt = false)
    {
        // When the given "event" is actually an object we will assume it is an event
        // object and use the class as the event name and this event itself as the
        // payload to the handler, which makes object based events quite simple.
        // 如果event是一个对象,则将事件本身装载到延迟加载,事件本身的类名作为事件处理
        if (is_object($event)) {
            list($payload, $event) = [[$event], get_class($event)];
        }

        $responses = [];

        // If an array is not given to us as the payload, we will turn it into one so
        // we can easily use call_user_func_array on the listeners, passing in the
        // payload to each of them so that they receive each of these arguments.
        // 如果延迟加载不是数组,则数组化
        if (! is_array($payload)) {
            $payload = [$payload];
        }

        $this->firing[] = $event;

        if (isset($payload[0]) && $payload[0] instanceof ShouldBroadcast) {
            $this->broadcastEvent($payload[0]);
        }

        foreach ($this->getListeners($event) as $listener) {
            $response = call_user_func_array($listener, $payload);

            // If a response is returned from the listener and event halting is enabled
            // we will just return this response, and not call the rest of the event
            // listeners. Otherwise we will add the response on the response list.
            // 如果返回值为null并且事件已停止,我们将通知队列弹出最上面的事件
            if (! is_null($response) && $halt) {
                array_pop($this->firing);

                return $response;
            }

            // If a boolean false is returned from a listener, we will stop propagating
            // the event to any further listeners down in the chain, else we keep on
            // looping through the listeners and firing every one in our sequence.
            // 如果返回值为假,则跳出.否则将返回值装进数组
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        array_pop($this->firing);

        return $halt ? null : $responses;
    }

    /**
     * Broadcast the given event class.
     * 广播通知给定的事件类
     *
     * @param  \Illuminate\Contracts\Broadcasting\ShouldBroadcast  $event
     * @return void
     */
    protected function broadcastEvent($event)
    {
        if ($this->queueResolver) {
            $connection = $event instanceof ShouldBroadcastNow ? 'sync' : null;

            $queue = method_exists($event, 'onQueue') ? $event->onQueue() : null;

            $this->resolveQueue()->connection($connection)->pushOn($queue, 'Illuminate\Broadcasting\BroadcastEvent', [
                'event' => serialize(clone $event),
            ]);
        }
    }

    /**
     * Get all of the listeners for a given event name.
     * 获取给定事件名称所有的监听者
     *
     * @param  string  $eventName
     * @return array
     */
    public function getListeners($eventName)
    {
        $wildcards = $this->getWildcardListeners($eventName);

        if (! isset($this->sorted[$eventName])) {
            $this->sortListeners($eventName);
        }

        return array_merge($this->sorted[$eventName], $wildcards);
    }

    /**
     * Get the wildcard listeners for the event.
     *
     * @param  string  $eventName
     * @return array
     */
    protected function getWildcardListeners($eventName)
    {
        $wildcards = [];

        foreach ($this->wildcards as $key => $listeners) {
            if (Str::is($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }

        return $wildcards;
    }

    /**
     * Sort the listeners for a given event by priority.
     * 根据权重对给定事件进行排序对应的监听者
     *
     * @param  string  $eventName
     * @return array
     */
    protected function sortListeners($eventName)
    {
        $this->sorted[$eventName] = [];

        // If listeners exist for the given event, we will sort them by the priority
        // so that we can call them in the correct order. We will cache off these
        // sorted event listeners so we do not have to re-sort on every events.
        // 如果存在某个事件的监听者,先排序,然后合并保存.
        if (isset($this->listeners[$eventName])) {
            krsort($this->listeners[$eventName]);

            $this->sorted[$eventName] = call_user_func_array(
                'array_merge', $this->listeners[$eventName]
            );
        }
    }

    /**
     * Register an event listener with the dispatcher.
     * 使用分发器注册一个事件监听者
     *
     * @param  mixed  $listener
     * @return mixed
     */
    public function makeListener($listener)
    {
        return is_string($listener) ? $this->createClassListener($listener) : $listener;
    }

    /**
     * Create a class based listener using the IoC container.
     * 使用IOC容器基于监听者创建一个类
     *
     * @param  mixed  $listener
     * @return \Closure
     */
    public function createClassListener($listener)
    {
        $container = $this->container;

        return function () use ($listener, $container) {
            return call_user_func_array(
                $this->createClassCallable($listener, $container), func_get_args()
            );
        };
    }

    /**
     * Create the class based event callable.
     * 创建一个基于事件可调用的类
     *
     * @param  string  $listener
     * @param  \Illuminate\Container\Container  $container
     * @return callable
     */
    protected function createClassCallable($listener, $container)
    {
        list($class, $method) = $this->parseClassCallable($listener);

        if ($this->handlerShouldBeQueued($class)) {
            return $this->createQueuedHandlerCallable($class, $method);
        } else {
            return [$container->make($class), $method];
        }
    }

    /**
     * Parse the class listener into class and method.
     * 解析类的监听者到类和方法(App\Listeners\ExampleListener@handle)
     *
     * @param  string  $listener
     * @return array
     */
    protected function parseClassCallable($listener)
    {
        $segments = explode('@', $listener);

        return [$segments[0], count($segments) == 2 ? $segments[1] : 'handle'];
    }

    /**
     * Determine if the event handler class should be queued.
     * 判断事件处理类是否应该队列化
     *
     * @param  string  $class
     * @return bool
     */
    protected function handlerShouldBeQueued($class)
    {
        try {
            return (new ReflectionClass($class))->implementsInterface(
                'Illuminate\Contracts\Queue\ShouldQueue'
            );
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create a callable for putting an event handler on the queue.
     * 在队列上创建一个可调用的事件处理
     *
     * @param  string  $class
     * @param  string  $method
     * @return \Closure
     */
    protected function createQueuedHandlerCallable($class, $method)
    {
        return function () use ($class, $method) {
            $arguments = $this->cloneArgumentsForQueueing(func_get_args());

            if (method_exists($class, 'queue')) {
                $this->callQueueMethodOnHandler($class, $method, $arguments);
            } else {
                $this->resolveQueue()->push('Illuminate\Events\CallQueuedHandler@call', [
                    'class' => $class, 'method' => $method, 'data' => serialize($arguments),
                ]);
            }
        };
    }

    /**
     * Clone the given arguments for queueing.
     * 将查询参数克隆到队列中
     *
     * @param  array  $arguments
     * @return array
     */
    protected function cloneArgumentsForQueueing(array $arguments)
    {
        return array_map(function ($a) {
            return is_object($a) ? clone $a : $a;
        }, $arguments);
    }

    /**
     * Call the queue method on the handler class.
     * 在处理类上调用队列方法
     *
     * @param  string  $class
     * @param  string  $method
     * @param  array  $arguments
     * @return void
     */
    protected function callQueueMethodOnHandler($class, $method, $arguments)
    {
        $handler = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $handler->queue($this->resolveQueue(), 'Illuminate\Events\CallQueuedHandler@call', [
            'class' => $class, 'method' => $method, 'data' => serialize($arguments),
        ]);
    }

    /**
     * Remove a set of listeners from the dispatcher.
     * 从分发器移除监听者集合
     *
     * @param  string  $event
     * @return void
     */
    public function forget($event)
    {
        if (Str::contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event], $this->sorted[$event]);
        }
    }

    /**
     * Forget all of the pushed listeners.
     * 移除所有已发布的监听者
     *
     * @return void
     */
    public function forgetPushed()
    {
        foreach ($this->listeners as $key => $value) {
            if (Str::endsWith($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }

    /**
     * Get the queue implementation from the resolver.
     * 获取解析器上的队列实现
     *
     * @return \Illuminate\Contracts\Queue\Queue
     */
    protected function resolveQueue()
    {
        return call_user_func($this->queueResolver);
    }

    /**
     * Set the queue resolver implementation.
     * 设置队列解析器实现
     *
     * @param  callable  $resolver
     * @return $this
     */
    public function setQueueResolver(callable $resolver)
    {
        $this->queueResolver = $resolver;

        return $this;
    }
}
