<?php
/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2018-12-27
 * Time: 01:41
 */

namespace EasySwoole\Component\Process;
use EasySwoole\Component\Timer;
use Swoole\Process;

abstract class AbstractProcess
{
    private $swooleProcess;
    private $processName;
    private $arg;
    private $maxExitWaitTime = 3;

    /**
     * @param int $maxExitWaitTime
     */
    public function setMaxExitWaitTime(int $maxExitWaitTime): void
    {
        $this->maxExitWaitTime = $maxExitWaitTime;
    }


    /**
     * AbstractProcess constructor.
     * @param string $processName
     * @param null $arg
     * @param bool $redirectStdinStdout
     * @param int $pipeType
     * @param bool $enableCoroutine
     */
    function __construct(...$args)
    {
        $arg1 = array_shift($args);
        if($arg1 instanceof Config){
            $processName = $arg1->getProcessName();
            $arg = $arg1->getArg();
            $pipeType = $arg1->getPipeType();
            $redirectStdinStdout = $arg1->isRedirectStdinStdout();
            $enableCoroutine = $arg1->isEnableCoroutine();
        }else{
            $processName = $arg1;
            $arg = array_shift($args);
            $redirectStdinStdout = (bool)array_shift($args) ?: false;
            $pipeType = array_shift($args);
            $pipeType = $pipeType === null ? Config::PIPE_TYPE_SOCK_DGRAM : $pipeType;
            $enableCoroutine = (bool)array_shift($args) ?: false;
        }
        $this->arg = $arg;
        $this->processName = $processName;
        $this->swooleProcess = new \swoole_process([$this,'__start'],$redirectStdinStdout,$pipeType,$enableCoroutine);
    }

    public function getProcess():Process
    {
        return $this->swooleProcess;
    }

    public function addTick($ms,callable $call):?int
    {
        return Timer::getInstance()->loop(
            $ms,$call
        );
    }

    public function clearTick(int $timerId):?int
    {
        return Timer::getInstance()->clear($timerId);
    }

    public function delay($ms,callable $call):?int
    {
        return Timer::getInstance()->after($ms,$call);
    }

    /*
     * 服务启动后才能获得到pid
     */
    public function getPid():?int
    {
        if(isset($this->swooleProcess->pid)){
            return $this->swooleProcess->pid;
        }else{
            return null;
        }
    }

    function __start(Process $process)
    {
        if(PHP_OS != 'Darwin' && !empty($this->processName)){
            $process->name($this->getProcessName());
        }

        Process::signal(SIGTERM,function ()use($process){
            go(function ()use($process){
                $new = iterator_to_array(\co::listCoroutines());
                try{
                    $this->onShutDown();
                }catch (\Throwable $throwable){
                    $this->onException($throwable);
                }
                swoole_event_del($process->pipe);
                Process::signal(SIGTERM,null);
                $old = iterator_to_array(\co::listCoroutines());
                $diff = array_diff($old,$new);
                if(empty($diff)){
                    $this->getProcess()->exit(0);
                    return;
                }
                $t = $this->maxExitWaitTime;
                while($t > 0){
                    $exit = true;
                    foreach ($diff as $cid){
                        if(\co::getBackTrace($cid,DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,1) == false){
                            $exit = true;
                        }else{
                            $exit = false;
                            continue;
                        }
                    }
                    if($exit){
                        break;
                    }
                    \co::sleep(0.01);
                    $t = $t - 0.01;
                }
                $this->getProcess()->exit(0);
            });
        });
        swoole_event_add($this->swooleProcess->pipe, function(){
            $msg = $this->swooleProcess->read(64 * 1024);
            $this->onReceive($msg);
        });
        try{
            $this->run($this->arg);
        }catch (\Throwable $throwable){
            $this->onException($throwable);
        }
    }

    public function getArg()
    {
        return $this->arg;
    }

    public function getProcessName()
    {
        return $this->processName;
    }

    protected function onException(\Throwable $throwable){
        throw $throwable;
    }

    public abstract function run($arg);
    public abstract function onShutDown();
    public abstract function onReceive(string $str);
}