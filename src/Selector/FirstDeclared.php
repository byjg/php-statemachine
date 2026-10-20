<?php

namespace ByJG\StateMachine\Selector;

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionSelectorInterface;

/**
 * The first transition that accepts the data wins.
 *
 * This is the machine's default. Because `$matching` is lazy, taking the first element stops
 * the evaluation there: the conditions of the transitions declared after it never run.
 *
 * The tie-break is the order the transitions were added to the machine, which is fine while
 * the conditions are mutually exclusive by construction and no tie can occur. When they are
 * not, the outcome depends on the order lines were typed — in a definition file, on the order
 * of entries in the file. Selector\HighestPriority states the intended order explicitly, and
 * Selector\RejectAmbiguous refuses to guess.
 */
class FirstDeclared implements TransitionSelectorInterface
{
    #[\Override]
    public function select(State $from, iterable $matching, ?array $data): ?Transition
    {
        foreach ($matching as $transition) {
            return $transition;
        }

        return null;
    }
}
