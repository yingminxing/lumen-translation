<?php

namespace Illuminate\Queue;

use Illuminate\Contracts\Queue\Job as JobContract;

trait InteractsWithQueue
{
    /**
     * The underlying queue job instance.
     * 获取任务实例
     *
     * @var \Illuminate\Contracts\Queue\Job
     */
    protected $job;

    /**
     * Get the number of times the job has been attempted.
     * 获取任务期望次数
     *
     * @return int
     */
    public function attempts()
    {
        return $this->job ? $this->job->attempts() : 1;
    }

    /**
     * Delete the job from the queue.
     * 队列删除任务
     *
     * @return void
     */
    public function delete()
    {
        if ($this->job) {
            return $this->job->delete();
        }
    }

    /**
     * Fail the job from the queue.
     * 将队列任务标示成失败
     *
     * @return void
     */
    public function failed()
    {
        if ($this->job) {
            return $this->job->failed();
        }
    }

    /**
     * Release the job back into the queue.
     * 将任务发布到队列中
     *
     * @param  int   $delay
     * @return void
     */
    public function release($delay = 0)
    {
        if ($this->job) {
            return $this->job->release($delay);
        }
    }

    /**
     * Set the base queue job instance.
     * 设置基础任务实例
     *
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @return $this
     */
    public function setJob(JobContract $job)
    {
        $this->job = $job;

        return $this;
    }
}
