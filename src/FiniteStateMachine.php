<?php

namespace ByJG\StateMachine;

use Closure;
use PHPUnit\Util\Exception;

class FiniteStateMachine
{
    protected array $transitionList = [];

    protected array $stateList = [];

    protected bool $throwError = false;

    public static function createMachine(array $transitionList = []): FiniteStateMachine
    {
        $stateList = [];
        $stateMachine = new FiniteStateMachine();
        foreach ($transitionList as $transition) {
            if (isset($stateList[$transition[0]])) {
                $st1 = $stateList[$transition[0]];
            } else {
                $st1 = new State($transition[0]);
                $stateList[$transition[0]] = $st1;
            }

            if (isset($stateList[$transition[1]])) {
                $st2 = $stateList[$transition[1]];
            } else {
                $st2 = new State($transition[1]);
                $stateList[$transition[1]] = $st2;
            }

            if (isset($transition[2])) {
                $stateMachine->addTransition(new Transition($st1, $st2, $transition[2]));
            } else {
                $stateMachine->addTransition(new Transition($st1, $st2));
            }
        }

        return $stateMachine;
    }

    public function throwErrorIfCannotTransition(): static
    {
        $this->throwError = true;
        return $this;
    }

    protected function getKey(State $currentState, State $desiredState): string
    {
        return $currentState->getState() . "___" . $desiredState->getState();
    }

    public function addTransition(Transition $transition): static
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
     * @param array $transitions
     * @return $this
     */
    public function addTransitions(array $transitions): static
    {
        foreach ($transitions as $transition) {
            $this->addTransition($transition);
        }
        return $this;
    }

    public function possibleTransitions(State $currentState): array
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
     * @param array $data
     * @return State|null
     * @throws TransitionException
     */
    public function autoTransitionFrom(State $currentState, array $data): ?State
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
    public function canTransition(State $currentState, State $desiredState, array $data = null): bool
    {
        $result = $this->checkIfCanTransition($currentState, $desiredState, $data);

        if ($this->throwError && !$result) {
            throw new TransitionException("Cannot transition from {$currentState} to {$desiredState}");
        }

        return $result;
    }

    protected function checkIfCanTransition(State $currentState, State $desiredState, array $data = null): bool
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
    public function state(string $state): ?State
    {
        if (isset($this->stateList[strtoupper($state)])) {
            return $this->stateList[strtoupper($state)];
        }

        return null;
    }

    public function isInitialState(State $state): bool
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getDesiredState() == $state) {
                return false;
            }
        }

        return true;
    }

    public function isFinalState(State $state): bool
    {
        foreach ($this->transitionList as $transition) {
            if ($transition->getCurrentState() == $state) {
                return false;
            }
        }

        return true;
    }
}
