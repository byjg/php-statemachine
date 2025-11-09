<?php

namespace ByJG\StateMachine;

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
     * @var TransitionConditionInterface|null
     */
    protected ?TransitionConditionInterface $transitionCondition;

    /**
     * @param State $currentState
     * @param State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     */
    public function __construct(State $currentState, State $desiredState, ?TransitionConditionInterface $transitionCondition = null)
    {
        $this->currentState = $currentState;
        $this->desiredState = $desiredState;
        $this->transitionCondition = $transitionCondition;
    }

    /**
     * @param State $currentState
     * @param State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @return Transition
     */
    public static function create(State $currentState, State $desiredState, ?TransitionConditionInterface $transitionCondition = null): Transition
    {
        return new Transition($currentState, $desiredState, $transitionCondition);
    }

    /**
     * @param State[] $currentState
     * @param State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @return Transition[]
     */
    public static function createMultiple(array $currentState, State $desiredState, ?TransitionConditionInterface $transitionCondition = null): array
    {
        $result = [];
        foreach ($currentState as $from) {
            $result[] = new Transition($from, $desiredState, $transitionCondition);
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
    public function getDesiredState(?array $data = null): State
    {
        $desiredState = clone $this->desiredState;
        $desiredState->setData($data);

        return $desiredState;
    }

    /**
     * @param array|null $data
     * @return bool
     */
    public function runTransitionFunction(?array $data): bool
    {
        if (!empty($this->transitionCondition)) {
            return $this->transitionCondition->canTransition($data);
        }

        return true;
    }
}
