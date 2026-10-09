<?php

namespace ByJG\StateMachine;

/**
 * Decides which move wins when the data satisfies more than one of them.
 *
 * `autoTransitionFrom()` answers two questions: which transitions leaving the current state
 * accept this data, and — when several do — which one is taken. The first question belongs to
 * the conditions. The second is policy, and this is where it lives.
 *
 * The selector never decides whether a move is *legal*: it only ever sees transitions whose
 * condition already returned true, and the machine rejects a transition it was not offered.
 * A selector can therefore be wrong about which move you wanted, but it cannot produce a state
 * reached through a condition that said no.
 *
 * The default is Selector\FirstDeclared, which is the behaviour the machine has always had.
 */
interface TransitionSelectorInterface
{
    /**
     * Picks one transition out of those that accepted the data, or none.
     *
     * `$matching` is evaluated lazily, in declaration order: the condition of the second
     * transition does not run until the second element is asked for. A selector that only
     * needs the first match therefore costs exactly one condition call, and one that needs
     * them all pays for what it uses. Conditions are required to be free of side effects, so
     * how many of them a selector chooses to run is a matter of cost, not of correctness.
     *
     * Returning `null` means "no move", and is indistinguishable to the caller from no
     * transition having matched at all — `autoTransitionFrom()` returns `null`, or throws
     * under `throwErrorIfCannotTransition()`. A selector that refuses to choose *because*
     * the data is ambiguous should throw a TransitionException saying so, the way
     * Selector\RejectAmbiguous does, rather than return null.
     *
     * @param State $from The state being left
     * @param iterable<Transition> $matching Lazily evaluated, in declaration order
     * @param array|null $data The data the conditions were evaluated against
     * @return Transition|null One of the transitions offered by $matching, or null for none
     */
    public function select(State $from, iterable $matching, ?array $data): ?Transition;
}
