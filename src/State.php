<?php

namespace ByJG\StateMachine;

/**
 * A state of the machine: a name, the data it was reached with, and the transition it
 * was reached through.
 *
 * States carry no behaviour of their own. What happens when a state is reached belongs
 * to the transition that reached it, so that the same state can behave differently
 * depending on where it was reached from, without having to be split in two.
 */
class State
{
    protected string $state;

    protected ?array $data = null;

    protected ?State $previousState = null;

    protected ?TransitionActionInterface $transitionAction = null;

    /**
     * @param string $state
     */
    public function __construct(string $state)
    {
        $this->state = $state;
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
     * @param State $from A copy of the state moved away from
     * @param TransitionActionInterface|null $action The action of the transition taken
     */
    public function arrivedThrough(State $from, ?TransitionActionInterface $action): void
    {
        $this->previousState = $from;
        $this->transitionAction = $action;
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
