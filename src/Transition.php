<?php

namespace ByJG\StateMachine;

use Closure;

class Transition
{
    /**
     * @var State
     */
    protected $currentState;

    /**
     * @var State
     */
    protected $desiredState;

    /**
     * @var Closure
     */
    protected $transitionFunction;

    /**
     * @param State $currentState
     * @param State $desiredState
     * @param Closure $transitionFunction
     */
    public function __construct(State $currentState, State $desiredState, Closure $transitionFunction = null)
    {
        $this->currentState = $currentState;
        $this->desiredState = $desiredState;
        $this->transitionFunction = $transitionFunction;
    }

    /**
     * @return State
     */
    public function getCurrentState()
    {
        return $this->currentState;
    }

    /**
     * @return State
     */
    public function getDesiredState()
    {
        return $this->desiredState;
    }

    public function runTransitionFunction($data)
    {
        if (!empty($this->transitionFunction)) {
            return call_user_func_array($this->transitionFunction, [$data]);
        }

        return true;
    }
}