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
     * @var string The name distinguishing this transition from others between the same two
     *             states. Empty when the move needs no distinguishing, which is the common case.
     */
    protected string $name;

    /**
     * Both ends are named by an enum case, the string it corresponds to, or a State.
     *
     * The names are normalised here but not validated: a Transition on its own has no enum to
     * check them against. FiniteStateMachine::addTransition() rejects any end that is not one
     * of its states, which is where a typo in a declaration is caught.
     *
     * A name is only needed when the same two states are joined more than once — DRAFT to PAID
     * by PIX, by card and by transfer are three moves, each with its own condition and its own
     * side effect. Leave it out when the pair of states says everything.
     *
     * @param string|\UnitEnum|State $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     * @param string|\UnitEnum|null $name Distinguishes this move from others between the same
     *                                    two states. Compared uppercased, like a state name.
     */
    public function __construct(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null,
        string|\UnitEnum|null $name = null
    ) {
        $this->currentState = new State(State::nameOf($currentState));
        $this->desiredState = new State(State::nameOf($desiredState));
        $this->transitionCondition = $transitionCondition;
        $this->transitionAction = $transitionAction;
        $this->name = is_null($name) ? "" : State::nameOf($name);
    }

    /**
     * Names the move, for when the same two states are joined more than once.
     *
     * Reads in the order the move is spoken about — "paid by PIX" — and keeps the name in front
     * of the condition and the action rather than trailing behind them:
     *
     *     Transition::named('PIX', OrderState::Draft, OrderState::Paid, $paidByPix, $confirmPix)
     *
     * @param string|\UnitEnum $name
     * @param string|\UnitEnum|State $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     * @return Transition
     */
    public static function named(
        string|\UnitEnum $name,
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null
    ): Transition {
        return new Transition($currentState, $desiredState, $transitionCondition, $transitionAction, $name);
    }

    /**
     * @param string|\UnitEnum|State $currentState
     * @param string|\UnitEnum|State $desiredState
     * @param TransitionConditionInterface|null $transitionCondition
     * @param TransitionActionInterface|null $transitionAction
     * @param string|\UnitEnum|null $name
     * @return Transition
     */
    public static function create(
        string|\UnitEnum|State $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null,
        string|\UnitEnum|null $name = null
    ): Transition {
        return new Transition($currentState, $desiredState, $transitionCondition, $transitionAction, $name);
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
     * @param string|\UnitEnum|null $name Shared by every transition produced, which is legal
     *                                    because they start from different states
     * @return Transition[]
     */
    public static function createMultiple(
        array $currentState,
        string|\UnitEnum|State $desiredState,
        ?TransitionConditionInterface $transitionCondition = null,
        ?TransitionActionInterface $transitionAction = null,
        string|\UnitEnum|null $name = null
    ): array {
        $result = [];
        foreach ($currentState as $from) {
            $result[] = new Transition($from, $desiredState, $transitionCondition, $transitionAction, $name);
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
     * The name distinguishing this move from others between the same two states.
     *
     * Empty when the move was not named, which is the common case: most pairs of states are
     * joined once and the pair already says which move it is.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * How this transition reads when it has to be told apart from another.
     */
    public function describe(): string
    {
        return $this->name === ""
            ? "{$this->currentState} -> {$this->desiredState}"
            : "{$this->currentState} -> {$this->desiredState} ({$this->name})";
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
