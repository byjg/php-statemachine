<?php

namespace ByJG\StateMachine;

use Closure;

class Transition
{
    /**
     * @var State
     */
    protected State $currentState;

    /**
     * @var State
     */
    protected State $desiredState;

    /**
     * @var Closure|null
     */
    protected ?Closure $transitionFunction;

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
    public static function create(State $currentState, State $desiredState, Closure $transitionFunction = null): Transition
    {
        return new Transition($currentState, $desiredState, $transitionFunction);
    }

    /**
     * @param State[] $currentState
     * @param State $desiredState
     * @param Closure|null $transitionFunction
     * @return Transition[]
     */
    public static function createMultiple(array $currentState, State $desiredState, Closure $transitionFunction = null): array
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
    public function getCurrentState(): State
    {
        return $this->currentState;
    }

    /**
     * @param array|null $data
     * @return State
     */
    public function getDesiredState(array $data = null): State
    {
        $desiredState = clone $this->desiredState;
        $desiredState->setData($data);

        return $desiredState;
    }

    /**
     * @param array|null $data
     * @return bool|mixed
     */
    public function runTransitionFunction(?array $data): mixed
    {
        if (!empty($this->transitionFunction)) {
            return call_user_func_array($this->transitionFunction, [$data]);
        }

        return true;
    }
}
