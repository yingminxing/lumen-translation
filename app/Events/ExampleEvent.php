<?php

namespace App\Events;

use Illuminate\Support\Facades\Log;

class ExampleEvent extends Event
{

    private $name = '';
    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($name)
    {
        Log::info('test', ['jzm1']);
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }
}
