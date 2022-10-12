<?php

namespace ByJG\StateMachine;

use Closure;

class State
{
    protected $state = null;

    /**
     * @var Closure
     */
    protected $stateFunction;

    protected $data;

    /**
     * @param string $state
     * @param Closure $stateFunction
     */
    public function __construct($state, Closure $stateFunction = null)
    {
        $this->state = $state;
        $this->stateFunction = $stateFunction;
    }

    public function __toString()
    {
        return $this->getState();
    }

    public function getState()
    {
        return strtoupper($this->state);
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData($data)
    {
        $this->data = $data;
    }

    public function process()
    {
        if (!empty($this->stateFunction)) {
            call_user_func_array($this->stateFunction, [$this->data]);
        }
    }
}
