<?php

namespace Orange\Coroutine;

use Orange\Async\Client\Base;
use Orange\Promise\Promise;

class Scheduler
{
    private $task = null;
    private $stack = null;

    public function __construct(Task $task)
    {
        $this->task = $task;
        $this->stack = new \SplStack();
    }

    public function schedule()
    {
        $coroutine = $this->task->getCoroutine();

        $value = $coroutine->current();

        $signal = $this->handleSysCall($value);
        if ($signal !== null) return $signal;

        $signal = $this->handleCoroutine($value);
        if ($signal !== null) return $signal;

        $signal = $this->handleAsyncJob($value);
        if ($signal !== null) return $signal;

        $signal = $this->handleYieldValue($value);
        if ($signal !== null) return $signal;

        $signal = $this->handleTaskStack($value);
        if ($signal !== null) return $signal;

        $signal = $this->checkTaskDone($value);
        if ($signal !== null) return $signal;

        return Signal::TASK_DONE;
    }

    public function isStackEmpty()
    {
        return $this->stack->isEmpty();
    }

    public function throwException($e, $isFirstCall = false, $isAsync = false)
    {
        if ($this->isTaskInvalid($e)) {
            return;
        }

        if ($this->isStackEmpty()) {
            $parent = $this->task->getParentTask();
            if (null !== $parent && $parent instanceof Task) {
                $parent->sendException($e);
            } else {
                $this->task->getCoroutine()->throw($e);
            }
            return;
        }

        try{
            if ($isFirstCall) {
                $coroutine = $this->task->getCoroutine();
            } else {
                $coroutine = $this->stack->pop();
            }

            $this->task->setCoroutine($coroutine);
            $coroutine->throw($e);

            if ($isAsync) {
                $this->task->run();
            }
        } catch (\Throwable $t){
            $this->throwException($t, false, $isAsync);
        } catch (\Exception $e){
            $this->throwException($e, false, $isAsync);
        }
    }

    public function asyncException($e)
    {
        if ($this->isTaskInvalid($e)) {
            return;
        }

        if ($this->isStackEmpty()) {
            $parent = $this->task->getParentTask();
            if (null !== $parent && $parent instanceof Task) {
                $parent->sendException($e);
            } else {
                $this->task->getCoroutine()->throw($e);
            }
            return;
        }

        try{
            $coroutine = $this->task->getCoroutine();
            $this->task->setCoroutine($coroutine);
            $coroutine->throw($e);

            $this->task->run();
        } catch (\Throwable $t){
            $this->throwException($t, false, true);
        } catch (\Exception $e){
            $this->throwException($e, false, true);
        }
    }

    public function asyncCallback($response)
    {
        $this->task->send($response);
        $this->task->run();
    }

    private function handleSysCall($value)
    {
        if (!($value instanceof SysCall)
            && !is_subclass_of($value, SysCall::class)
        ) {
            return null;
        }

        $signal = call_user_func($value, $this->task);
        if (Signal::isSignal($signal)) {
            return $signal;
        }

        return null;
    }

    private function handleCoroutine($value)
    {
        if (!($value instanceof \Generator)) {
            return null;
        }

        $coroutine = $this->task->getCoroutine();
        $this->stack->push($coroutine);
        $this->task->setCoroutine($value);

        return Signal::TASK_CONTINUE;
    }

    private function handleAsyncJob($value)
    {
        if ($value instanceof Promise) {
            $value->then([$this, 'asyncCallback'])
                ->eCatch([$this, 'asyncException']);

            return Signal::TASK_WAIT;
        } else {
            return null;
        }
    }

    private function handleTaskStack($value)
    {
        if ($this->isStackEmpty()) {
            return null;
        }

        $coroutine = $this->stack->pop();
        $this->task->setCoroutine($coroutine);

        $value = $this->task->getSendValue();
        $this->task->send($value);

        return Signal::TASK_CONTINUE;
    }

    private function handleYieldValue($value)
    {
        $coroutine = $this->task->getCoroutine();
        if (!$coroutine->valid()) {
            return null;
        }

        $status = $this->task->send($value);
        return Signal::TASK_CONTINUE;
    }

    private function checkTaskDone($value)
    {
        $coroutine = $this->task->getCoroutine();
        if ($coroutine->valid()) {
            return null;
        }

        return Signal::TASK_DONE;
    }

    private function isTaskInvalid($t)
    {
        $status = $this->task->getStatus();
        if ($status === Signal::TASK_KILLED || $status === Signal::TASK_DONE) {
            // 兼容PHP7 & PHP5
            if ($t instanceof \Throwable || $t instanceof \Exception) {
                //app('syncLog')->error($t->getMessage(), ['code' => $t->getCode(), 'trace' => $t->getTraceAsString()]);
                //todo echo exception
            }
            return true;
        }

        return false;
    }
}
