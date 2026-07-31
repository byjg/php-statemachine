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
     * @var TransitionActionInterface|null
     */
    protected ?TransitionActionInterface $transitionAction;

    /**
     * Both ends are named by an enum case, the string it corresponds to, or a State.
     *
     * The names are normalised here but not validated: a Transition on its own has no enum to
     * check them against. FiniteStateMachine::addTransition() rejects any end that is not one
     * of its states, which is where a typo in a declaration is caught.
     *
     * @param string|\UnitEnum|State $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     */
    public function __construct(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null
    ) {
        $this->currentState = new State(State::nameOf($currentState));
        $this->desiredState = new State(State::nameOf($desiredState));
        $this->transitionCondition = $transitionCondition;
        $this->transitionAction = $transitionAction;
    }

    /**
     * @param string|\UnitEnum|State $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     * @return Transition
     */
    public static function create(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null
    ): Transition {
        return new Transition($currentState, $desiredState, $transitionCondition, $transitionAction);
    }

    /**
     * Creates one transition per origin state, all sharing the same condition and action.
     *
     * This is how a side effect that must happen on every way into a state is declared
     * once instead of being repeated per transition.
     *
     * @param array<string|\UnitEnum|State> $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     * @return Transition[]
     */
    public static function createMultiple(
        array $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null
    ): array {
        $result = [];
        foreach ($currentState as $from) {
            $result[] = new Transition($from, $desiredState, $transitionCondition, $transitionAction);
        }
        return $result;
    }

    /**
     * Returns a copy of the origin state, so the caller cannot mutate the transition.
     *
     * @return State
     */
    public function getCurrentState(): State
    {
        return clone $this->currentState;
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
     * @return TransitionActionInterface|null
     */
    public function getTransitionAction(): ?TransitionActionInterface
    {
        return $this->transitionAction;
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
