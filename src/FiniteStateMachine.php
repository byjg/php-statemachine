<?php

namespace ByJG\StateMachine;

use PHPUnit\Util\Exception;

class FiniteStateMachine
{
    protected $transitionList = [];

    protected $throwError = false;

    public static function createMachine()
    {
        return new FiniteStateMachine();
    }

    public function throwErrorIfCannotTransition()
    {
        $this->throwError = true;
        return $this;
    }

    protected function getKey($currentState, $desiredState)
    {
        return $currentState->getState() . "___" . $desiredState->getState();
    }

    public function addTransition(Transition $transition)
    {
        $this->transitionList[$this->getKey($transition->getCurrentState(), $transition->getDesiredState())] = $transition;
        return $this;
    }

    public function getNextStates(State $currentState)
    {
        $next = array_map(function ($key, $value) use ($currentState) {
            if (strpos($key, "${currentState}___") === 0) {
                return $value->getDesiredState();
            }
            return null;
        }, array_keys($this->transitionList), array_values($this->transitionList));

        return array_filter($next);
    }

    public function getPreviousStates(State $currentState)
    {
        $next = array_map(function ($key, $value) use ($currentState) {
            if (strpos($key, "___${currentState}") !== false) {
                return $value->getCurrentState();
            }
            return null;
        }, array_keys($this->transitionList), array_values($this->transitionList));

        return array_filter($next);
    }

    public function getTransition(State $currentState, State $desiredState)
    {
        if (isset($this->transitionList[$this->getKey($currentState, $desiredState)])) {
            return $this->transitionList[$this->getKey($currentState, $desiredState)];
        }

        return null;
    }

    /**
     * @throws TransitionException
     */
    public function canTransition(State $currentState, State $desiredState, $data = null)
    {
        $result = $this->_canTransition($currentState, $desiredState, $data);

        if ($this->throwError && !$result) {
            throw new TransitionException("Cannot transition from ${currentState} to ${desiredState}");
        }

        return $result;
    }

    protected function _canTransition(State $currentState, State $desiredState, $data = null)
    {
        $transition = $this->getTransition($currentState, $desiredState);

        if (empty($transition)) {
            return false;
        }

        return $transition->runTransitionFunction($data);
    }

}