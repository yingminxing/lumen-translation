<?php

namespace Illuminate\Contracts\Bus;

interface Dispatcher
{
    /**
     * Dispatch a command to its appropriate handler.
     * 分发脚本到合适的处理器
     *
     * @param  mixed  $command
     * @param  \Closure|null  $afterResolving
     * @return mixed
     */
    public function dispatch($command);

    /**
     * Dispatch a command to its appropriate handler in the current process.
     * 在当前的处理中分发脚本到合适的处理器
     *
     * @param  mixed  $command
     * @param  \Closure|null  $afterResolving
     * @return mixed
     */
    public function dispatchNow($command);

    /**
     * Set the pipes commands should be piped through before dispatching.
     * 在分发前设置必要的管道命令
     *
     * @param  array  $pipes
     * @return $this
     */
    public function pipeThrough(array $pipes);
}
