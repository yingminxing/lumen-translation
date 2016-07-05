<?php

namespace Laravel\Lumen\Providers;

use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     * 应用的事件监听
     *
     * @var array
     */
    protected $listen = [];

    /**
     * The subscriber classes to register.
     * 注册订阅类
     *
     * @var array
     */
    protected $subscribe = [];

    /**
     * Register the application's event listeners.
     * 注册应用的事件监听
     *
     * @return void
     */
    public function boot()
    {
        $events = app('events');

        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                $events->listen($event, $listener);
            }
        }

        foreach ($this->subscribe as $subscriber) {
            $events->subscribe($subscriber);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function register()
    {
        //
    }

    /**
     * Get the events and handlers.
     * 获取事件与处理器
     *
     * @return array
     */
    public function listens()
    {
        return $this->listen;
    }
}
