<?php

namespace ByJG\StateMachine;

use PHPUnit\Util\Exception;

class FiniteStateMachine
{
    protected $transitionList = [];

    protected $stateList = [];

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
        $this->transitionList[
            $this->getKey($transition->getCurrentState(), $transition->getDesiredState())
        ] = $transition;

        if (!isset($this->stateList[$transition->getCurrentState()->getState()])) {
            $this->stateList[$transition->getCurrentState()->getState()] = $transition->getCurrentState();
        }
        if (!isset($this->stateList[$transition->getDesiredState()->getState()])) {
            $this->stateList[$transition->getDesiredState()->getState()] = $transition->getDesiredState();
        }
        return $this;
    }

    /**
     * @param Transition[] $transitions
     * @return $this
     */
    public function addTransitions($transitions)
    {
        foreach ($transitions as $transition) {
            $this->addTransition($transition);
        }
        return $this;
    }

    public function possibleTransitions(State $currentState)
    {
        $next = array_map(function ($key, $value) use ($currentState) {
            if (strpos($key, "{$currentState}___") === 0) {
                return $value;
            }
            return null;
        }, array_keys($this->transitionList), array_values($this->transitionList));

        return array_values(array_filter($next));
    }

    public function getTransition(State $currentState, State $desiredState)
    {
        if (isset($this->transitionList[$this->getKey($currentState, $desiredState)])) {
            return $this->transitionList[$this->getKey($currentState, $desiredState)];
        }

        return null;
    }

    /**
     * Undocumented function
     *
     * @param State $currentState
     * @param mixed $data
     * @return State
     */
    public function autoTransitionFrom(State $currentState, $data)
    {
        $transitions = $this->possibleTransitions($currentState);

        /**
         * @var Transition $transition
         */
        foreach ($transitions as $transition) {
            if ($transition->runTransitionFunction($data)) {
                return $transition->getDesiredState($data);
            }
        }

        if ($this->throwError) {
            throw new TransitionException(
                "There is not possible transitions from {$currentState} with the data provided"
            );
        }

        return null;
    }

    /**
     * @throws TransitionException
     */
    public function canTransition(State $currentState, State $desiredState, $data = null)
    {
        $result = $this->checkIfCanTransition($currentState, $desiredState, $data);

        if ($this->throwError && !$result) {
            throw new TransitionException("Cannot transition from {$currentState} to {$desiredState}");
        }

        return $result;
    }

    protected function checkIfCanTransition(State $currentState, State $desiredState, $data = null)
    {
        $transition = $this->getTransition($currentState, $desiredState);

        if (empty($transition)) {
            return false;
        }

        return $transition->runTransitionFunction($data);
    }

    /**
     * @param string $state
     */
    public function stateFactory($state)
    {
        if (isset($this->stateList[strtoupper($state)])) {
            return $this->stateList[strtoupper($state)];
        }

        return null;
    }

    public function isInitialState(State $state)
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getDesiredState() == $state) {
                return false;
            }
        }

        return true;
    }

    public function isFinalState(State $state)
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getCurrentState() == $state) {
                return false;
            }
        }

        return true;
    }
}
