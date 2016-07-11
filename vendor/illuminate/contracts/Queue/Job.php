<?php

namespace Illuminate\Contracts\Queue;

interface Job
{
    /**
     * Fire the job.
     * 通知任务
     *
     * @return void
     */
    public function fire();

    /**
     * Delete the job from the queue.
     * 队列删除任务
     *
     * @return void
     */
    public function delete();

    /**
     * Determine if the job has been deleted.
     * 判断此任务是否被删除
     *
     * @return bool
     */
    public function isDeleted();

    /**
     * Release the job back into the queue.
     * 发布任务到队列
     *
     * @param  int   $delay
     * @return void
     */
    public function release($delay = 0);

    /**
     * Determine if the job has been deleted or released.
     * 判断此任务是否被删除或者发布
     *
     * @return bool
     */
    public function isDeletedOrReleased();

    /**
     * Get the number of times the job has been attempted.
     *
     *
     * @return int
     */
    public function attempts();

    /**
     * Get the name of the queued job class.
     * 获取队列任务类的名称
     *
     * @return string
     */
    public function getName();

    /**
     * Call the failed method on the job instance.
     *
     * @return void
     */
    public function failed();

    /**
     * Get the name of the queue the job belongs to.
     * 获取队列任务名称
     *
     * @return string
     */
    public function getQueue();

     /**
      * Get the raw body string for the job.
      * 获取任务的原始body字符串
      *
      * @return string
      */
     public function getRawBody();
}
