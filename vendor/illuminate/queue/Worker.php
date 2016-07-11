<?php

namespace Illuminate\Queue;

use Exception;
use Throwable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Illuminate\Contracts\Cache\Repository as CacheContract;

class Worker
{
    /**
     * The queue manager instance.
     *
     * @var \Illuminate\Queue\QueueManager
     */
    protected $manager;

    /**
     * The failed job provider implementation.
     *
     * @var \Illuminate\Queue\Failed\FailedJobProviderInterface
     */
    protected $failer;

    /**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The cache repository implementation.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The exception handler instance.
     *
     * @var \Illuminate\Foundation\Exceptions\Handler
     */
    protected $exceptions;

    /**
     * Create a new queue worker.
     * 创建一个新的队列工作者
     *
     * @param  \Illuminate\Queue\QueueManager  $manager
     * @param  \Illuminate\Queue\Failed\FailedJobProviderInterface  $failer
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function __construct(QueueManager $manager,
                                FailedJobProviderInterface $failer = null,
                                Dispatcher $events = null)
    {
        $this->failer = $failer;
        $this->events = $events;
        $this->manager = $manager;
    }

    /**
     * Listen to the given queue in a loop.
     * 循环监听给定队列
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  int     $delay
     * @param  int     $memory
     * @param  int     $sleep
     * @param  int     $maxTries
     * @return array
     */
    public function daemon($connectionName, $queue = null, $delay = 0, $memory = 128, $sleep = 3, $maxTries = 0)
    {
        $lastRestart = $this->getTimestampOfLastQueueRestart();

        while (true) {
            if ($this->daemonShouldRun()) {
                $this->runNextJobForDaemon(
                    $connectionName, $queue, $delay, $sleep, $maxTries
                );
            } else {
                $this->sleep($sleep);
            }

            if ($this->memoryExceeded($memory) || $this->queueShouldRestart($lastRestart)) {
                $this->stop();
            }
        }
    }

    /**
     * Run the next job for the daemon worker.
     * 为后台程序运行下一个任务
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  int  $delay
     * @param  int  $sleep
     * @param  int  $maxTries
     * @return void
     */
    protected function runNextJobForDaemon($connectionName, $queue, $delay, $sleep, $maxTries)
    {
        try {
            $this->pop($connectionName, $queue, $delay, $sleep, $maxTries);
        } catch (Exception $e) {
            if ($this->exceptions) {
                $this->exceptions->report($e);
            }
        } catch (Throwable $e) {
            if ($this->exceptions) {
                $this->exceptions->report(new FatalThrowableError($e));
            }
        }
    }

    /**
     * Determine if the daemon should process on this iteration.
     * 判断这个后台程序是否应该在这个迭代器中执行
     *
     * @return bool
     */
    protected function daemonShouldRun()
    {
        return $this->manager->isDownForMaintenance()
                    ? false : $this->events->until('illuminate.queue.looping') !== false;
    }

    /**
     * Listen to the given queue.
     * 监听给定的队列
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  int     $delay
     * @param  int     $sleep
     * @param  int     $maxTries
     * @return array
     */
    public function pop($connectionName, $queue = null, $delay = 0, $sleep = 3, $maxTries = 0)
    {
        try {
            $connection = $this->manager->connection($connectionName);

            $job = $this->getNextJob($connection, $queue);

            // If we're able to pull a job off of the stack, we will process it and
            // then immediately return back out. If there is no job on the queue
            // we will "sleep" the worker for the specified number of seconds.
            // 如果我们将任务从队列栈中去除,我们将处理并返回它.如果队列中没有任务,我们将休眠固定几秒钟
            if (! is_null($job)) {
                return $this->process(
                    $this->manager->getName($connectionName), $job, $maxTries, $delay
                );
            }
        } catch (Exception $e) {
            if ($this->exceptions) {
                $this->exceptions->report($e);
            }
        }

        $this->sleep($sleep);

        return ['job' => null, 'failed' => false];
    }

    /**
     * Get the next job from the queue connection.
     * 从连接的队列获取下一个任务
     *
     * @param  \Illuminate\Contracts\Queue\Queue  $connection
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    protected function getNextJob($connection, $queue)
    {
        if (is_null($queue)) {
            return $connection->pop();
        }

        foreach (explode(',', $queue) as $queue) {
            if (! is_null($job = $connection->pop($queue))) {
                return $job;
            }
        }
    }

    /**
     * Process a given job from the queue.
     * 从队列中处理给定的任务
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  int  $maxTries
     * @param  int  $delay
     * @return array|null
     *
     * @throws \Throwable
     */
    public function process($connection, Job $job, $maxTries = 0, $delay = 0)
    {
        // 如果最大尝试次数大于0,并且任务的尝试次数大于最大值,则返回失败
        if ($maxTries > 0 && $job->attempts() > $maxTries) {
            return $this->logFailedJob($connection, $job);
        }

        try {
            $this->raiseBeforeJobEvent($connection, $job);

            // First we will fire off the job. Once it is done we will see if it will be
            // automatically deleted after processing and if so we'll fire the delete
            // method on the job. Otherwise, we will just keep on running our jobs.
            // 我们先判断任务是否失败,如果是的话,我们将删除该任务.否则我们将保持运行我们的任务
            $job->fire();

            $this->raiseAfterJobEvent($connection, $job);

            return ['job' => $job, 'failed' => false];
        } catch (Exception $e) {
            $this->handleJobException($connection, $job, $delay, $e);
        } catch (Throwable $e) {
            $this->handleJobException($connection, $job, $delay, $e);
        }
    }

    /**
     * Handle an exception that occurred while the job was running.
     * 当任务正在运行的时候处理运行时异常
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  int  $delay
     * @param  \Throwable  $e
     * @return void
     *
     * @throws \Throwable
     */
    protected function handleJobException($connection, Job $job, $delay, $e)
    {
        // If we catch an exception, we will attempt to release the job back onto
        // the queue so it is not lost. This will let is be retried at a later
        // time by another listener (or the same one). We will do that here.
        // 如果我们捕获到异常,我们将尝试释放任务回到队列以至于不会丢失.这会使后面的监听者再次监听
        try {
            $this->raiseExceptionOccurredJobEvent(
                $connection, $job, $e
            );
        } finally {
            if (! $job->isDeleted()) {
                $job->release($delay);
            }
        }

        throw $e;
    }

    /**
     * Raise the before queue job event.
     * 在队列任务事件前通知
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @return void
     */
    protected function raiseBeforeJobEvent($connection, Job $job)
    {
        if ($this->events) {
            $data = json_decode($job->getRawBody(), true);

            $this->events->fire(new Events\JobProcessing($connection, $job, $data));
        }
    }

    /**
     * Raise the after queue job event.
     * 在队列任务事件后通知
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @return void
     */
    protected function raiseAfterJobEvent($connection, Job $job)
    {
        if ($this->events) {
            $data = json_decode($job->getRawBody(), true);

            $this->events->fire(new Events\JobProcessed($connection, $job, $data));
        }
    }

    /**
     * Raise the exception occurred queue job event.
     * 通知在队列任务事件中发生的异常
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  \Throwable  $exception
     * @return void
     */
    protected function raiseExceptionOccurredJobEvent($connection, Job $job, $exception)
    {
        if ($this->events) {
            $data = json_decode($job->getRawBody(), true);

            $this->events->fire(new Events\JobExceptionOccurred($connection, $job, $data, $exception));
        }
    }

    /**
     * Log a failed job into storage.
     * 将已经失败的任务记录到存储中
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @return array
     */
    protected function logFailedJob($connection, Job $job)
    {
        if ($this->failer) {
            $failedId = $this->failer->log($connection, $job->getQueue(), $job->getRawBody());

            $job->delete();

            $job->failed();

            $this->raiseFailedJobEvent($connection, $job, $failedId);
        }

        return ['job' => $job, 'failed' => true];
    }

    /**
     * Raise the failed queue job event.
     * 通知任务事件已经失败
     *
     * @param  string  $connection
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  int|null  $failedId
     * @return void
     */
    protected function raiseFailedJobEvent($connection, Job $job, $failedId)
    {
        if ($this->events) {
            $data = json_decode($job->getRawBody(), true);

            $this->events->fire(new Events\JobFailed($connection, $job, $data, $failedId));
        }
    }

    /**
     * Determine if the memory limit has been exceeded.
     * 判断内存限制是否溢出(单位是M)
     *
     * @param  int   $memoryLimit
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage() / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     * 停止监听,跳出脚本
     *
     * @return void
     */
    public function stop()
    {
        $this->events->fire(new Events\WorkerStopping);

        die;
    }

    /**
     * Sleep the script for a given number of seconds.
     * 将脚本休眠给定的秒数
     *
     * @param  int   $seconds
     * @return void
     */
    public function sleep($seconds)
    {
        sleep($seconds);
    }

    /**
     * Get the last queue restart timestamp, or null.
     * 获取最近队列重启的时间戳
     *
     * @return int|null
     */
    protected function getTimestampOfLastQueueRestart()
    {
        if ($this->cache) {
            return $this->cache->get('illuminate:queue:restart');
        }
    }

    /**
     * Determine if the queue worker should restart.
     * 判断给定的队列工作者是否应该重启
     *
     * @param  int|null  $lastRestart
     * @return bool
     */
    protected function queueShouldRestart($lastRestart)
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Set the exception handler to use in Daemon mode.
     * 使用后台程序模式设置异常处理
     *
     * @param  \Illuminate\Contracts\Debug\ExceptionHandler  $handler
     * @return void
     */
    public function setDaemonExceptionHandler(ExceptionHandler $handler)
    {
        $this->exceptions = $handler;
    }

    /**
     * Set the cache repository implementation.
     * 设置缓存仓库
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @return void
     */
    public function setCache(CacheContract $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get the queue manager instance.
     * 获取队列管理实例
     *
     * @return \Illuminate\Queue\QueueManager
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * Set the queue manager instance.
     * 设置队列管理实例
     *
     * @param  \Illuminate\Queue\QueueManager  $manager
     * @return void
     */
    public function setManager(QueueManager $manager)
    {
        $this->manager = $manager;
    }
}
