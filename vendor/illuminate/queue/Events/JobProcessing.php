<?php

namespace Illuminate\Queue\Events;

class JobProcessing
{
    /**
     * The connection name.
     * 连接名称
     *
     * @var string
     */
    public $connectionName;

    /**
     * The job instance.
     * 任务实例
     *
     * @var \Illuminate\Contracts\Queue\Job
     */
    public $job;

    /**
     * The data given to the job.
     * 给任务的数据
     *
     * @var array
     */
    public $data;

    /**
     * Create a new event instance.
     * 创建一个新的事件实例
     *
     * @param  string  $connectionName
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  array  $data
     * @return void
     */
    public function __construct($connectionName, $job, $data)
    {
        $this->job = $job;
        $this->data = $data;
        $this->connectionName = $connectionName;
    }
}
