<?php

namespace Orange\Async\Pool;

use splQueue;

abstract class Pool
{   
    //splQueue
    protected $poolQueue;

    //splQueue
    protected $taskQueue;

    //最大连接数
    protected $maxPool = 5;

    protected $minPool = 2;

    //配置
    protected $config;

    //连接池资源
    protected $resources = [];

    protected $ableCount = 0;

    protected $timeout = 5;

    protected $calltime;

    public function __construct()
    {
        $this->poolQueue = new splQueue();
        $this->taskQueue = new splQueue();

        $this->createResources();
    }

    //初始化连接数
    abstract public function createResources();

    /**
     * 关闭连接池
     */
    abstract public function close();

    abstract public function doTask();

    public function request($method, $parameters, callable $callback, $taskId = 0)
    {
        //入队列
        $this->taskQueue->enqueue(['method' => $method, 'parameters' => $parameters, 'callback' => $callback, 'taskId' => $taskId]);

        if (!$this->poolQueue->isEmpty()) {
            $this->doTask();
        } else {
            $this->createResources();
        }
    }

    public function remove($resource)
    {
        unset($this->resources[spl_object_hash($resource)]);
        $this->ableCount--;
    }

    /**
     * put一个资源
     */ 
    public function put($resource)
    {
        $this->resources[spl_object_hash($resource)] = $resource;
        $this->poolQueue->enqueue($resource);

        if (!$this->taskQueue->isEmpty()) {
            $this->doTask();
        }
    }

    /**
     * 释放资源入队列
     */ 
    public function release($resource)
    {
        $this->poolQueue->enqueue($resource);

        if (!$this->taskQueue->isEmpty()) {
            $this->doTask();
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
