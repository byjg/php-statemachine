<?php

namespace ByJG\StateMachine;

/**
 * Represents the side effect of moving along one specific transition.
 *
 * The state machine NEVER calls this interface. It is triggered only when the caller
 * explicitly invokes State::process() on a state produced by a transition. The machine
 * decides whether a move is legal; the caller decides when it actually happened.
 *
 * Because of that, the caller owns the ordering. Persist the new state first, then
 * process it:
 *
 *     $next = $stateMachine->autoTransitionFrom($current, $data);
 *     $repository->save($entity, $next);  // commit the move
 *     $next->process();                   // then run the side effects
 *
 * If the action ran before persistence and the write failed, the side effect (email,
 * webhook, payment) would already be out with no state to match it.
 *
 * Unlike TransitionConditionInterface, this is the right place for side effects.
 */
interface TransitionActionInterface
{
    /**
     * Executes the side effect of the transition.
     *
     * Receiving both ends of the transition makes a single action object reusable
     * across many transitions, which is how cross-cutting concerns such as audit
     * logging are expressed.
     *
     * @param State $from The state the machine moved away from
     * @param State $to The state that was reached, carrying the data
     * @param array|null $data The data used to validate the transition
     * @return void
     */
    public function execute(State $from, State $to, ?array $data): void;
}
