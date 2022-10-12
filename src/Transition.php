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
     * @param Closure|null $transitionFunction
     */
    public function __construct(State $currentState, State $desiredState, Closure $transitionFunction = null)
    {
        $this->currentState = $currentState;
        $this->desiredState = $desiredState;
        $this->transitionFunction = $transitionFunction;
    }

    /**
     * @param State $currentState
     * @param State $desiredState
     * @param Closure|null $transitionFunction
     * @return Transition
     */
    public static function create(State $currentState, State $desiredState, Closure $transitionFunction = null)
    {
        return new Transition($currentState, $desiredState, $transitionFunction);
    }

    /**
     * @param State[] $currentState
     * @param State $desiredState
     * @param Closure|null $transitionFunction
     * @return Transition[]
     */
    public static function createMultiple($currentState, State $desiredState, Closure $transitionFunction = null)
    {
        $result = [];
        foreach ($currentState as $from) {
            $result[] = new Transition($from, $desiredState, $transitionFunction);
        }
        return $result;
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
    public function getDesiredState($data = null)
    {
        $desiredState = clone $this->desiredState;
        $desiredState->setData($data);

        return $desiredState;
    }

    /**
     * @param $data
     * @return bool|mixed
     */
    public function runTransitionFunction($data)
    {
        if (!empty($this->transitionFunction)) {
            return call_user_func_array($this->transitionFunction, [$data]);
        }

        return true;
    }
}
