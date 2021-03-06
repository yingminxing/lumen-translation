<?php

namespace App\Listeners;

use App\Events\ExampleEvent;
use Illuminate\Support\Facades\Log;

class ExampleListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        Log::info('test', ['jzm2']);
    }

    /**
     * Handle the event.
     *
     * @param  ExampleEvent  $event
     * @return void
     */
    public function handle(ExampleEvent $event)
    {
        if ($event->getName() == 'jzm') {
            Log::info('test', [$event->getName()]);
        } else {
            Log::info('test', ['test11']);
        }
    }
}
