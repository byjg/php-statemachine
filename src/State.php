<?php

namespace ByJG\StateMachine;

/**
 * A state of the machine: a name, the data it was reached with, and the transition it
 * was reached through.
 *
 * States carry no behaviour of their own. What happens when a state is reached belongs
 * to the transition that reached it, so that the same state can behave differently
 * depending on where it was reached from, without having to be split in two.
 *
 * A State is produced by the machine, never by the caller. Everything that identifies a
 * state — its name — is expressed by a case of the enum the machine is defined by, and a
 * State adds to that the data it was reached with and where it was reached from. Building
 * one by hand therefore yields an object whose data and origin cannot mean anything.
 */
class State
{
    protected string $state;

    protected ?array $data = null;

    protected ?State $previousState = null;

    protected ?TransitionActionInterface $transitionAction = null;

    protected ?string $transitionName = null;

    /**
     * Name a state to the machine with an enum case or a string, not with a new State.
     *
     * @internal Produced by FiniteStateMachine and Transition. Not part of the public API.
     * @param string $state
     */
    public function __construct(string $state)
    {
        $this->state = $state;
    }

    /**
     * The uppercased name of whatever was used to refer to a state.
     *
     * Backed enums are named by their value and pure enums by their case name, so a case,
     * the string it corresponds to, and a State carrying it all resolve to one name. This is
     * what lets an enum query a machine whose graph was defined in YAML.
     *
     * The name is not checked against any machine here — this only reads the reference. It is
     * FiniteStateMachine that decides whether the name is one of its states.
     *
     * @internal
     */
    public static function nameOf(string|\UnitEnum|State $state): string
    {
        if ($state instanceof State) {
            return $state->getState();
        }

        // BackedEnum extends UnitEnum, so it has to be tested first
        if ($state instanceof \BackedEnum) {
            return strtoupper((string)$state->value);
        }

        if ($state instanceof \UnitEnum) {
            return strtoupper($state->name);
        }

        return strtoupper($state);
    }

    public function __toString()
    {
        return $this->getState();
    }

    public function getState(): string
    {
        return strtoupper($this->state);
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): void
    {
        $this->data = $data;
    }

    /**
     * Records the transition this state was reached through.
     *
     * Called by the state machine when a transition is performed. A state that was not
     * produced by a transition has no origin and no action, and process() does nothing.
     *
     * @internal Called by FiniteStateMachine.
     * @param Transition $transition The transition taken
     */
    public function arrivedThrough(Transition $transition): void
    {
        $this->previousState = $transition->getCurrentState();
        $this->transitionAction = $transition->getTransitionAction();
        $this->transitionName = $transition->getName();
    }

    /**
     * The name of the transition this state was reached through, or null when it was not
     * reached by a transition.
     *
     * Empty string when the transition was not named. This is what tells apart the routes into
     * a state that several transitions lead to — which of PIX, card or transfer got you to PAID
     * — so it is worth persisting alongside the state itself.
     */
    public function getTransitionName(): ?string
    {
        return $this->transitionName;
    }

    /**
     * The state this one was reached from, or null when it was not reached by a transition.
     */
    public function getPreviousState(): ?State
    {
        return $this->previousState;
    }

    /**
     * Runs the action of the transition this state was reached through.
     *
     * Does nothing when the state was not produced by a transition, or when the
     * transition taken has no action attached.
     */
    public function process(): void
    {
        if (empty($this->transitionAction) || empty($this->previousState)) {
            return;
        }

        $this->transitionAction->execute($this->previousState, $this, $this->data);
    }
}
