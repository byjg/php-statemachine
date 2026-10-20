<?php

namespace ByJG\StateMachine\Selector;

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionException;
use ByJG\StateMachine\TransitionSelectorInterface;

/**
 * Exactly one transition may accept the data; more than one is an error.
 *
 * Use this when the conditions leaving a state are *meant* to be mutually exclusive and you
 * want to be told when they are not, instead of silently getting whichever one happens to be
 * declared first.
 *
 * This is what `throwErrorIfAmbiguousTransition()` installs. It evaluates every condition of
 * the current state rather than stopping at the first match, which is affordable only because
 * conditions are required to be free of side effects.
 */
class RejectAmbiguous implements TransitionSelectorInterface
{
    /**
     * @throws TransitionException If the data satisfies more than one transition
     */
    #[\Override]
    public function select(State $from, iterable $matching, ?array $data): ?Transition
    {
        $matched = [];
        foreach ($matching as $transition) {
            $matched[] = $transition;
        }

        if (count($matched) > 1) {
            $candidates = implode(", ", array_map(
                fn (Transition $item): string => $item->getName() === ""
                    ? $item->getDesiredState()->getState()
                    : "{$item->getDesiredState()} ({$item->getName()})",
                $matched
            ));

            throw new TransitionException(
                "Ambiguous transition from {$from}: the data provided matches {$candidates}"
            );
        }

        return $matched[0] ?? null;
    }
}
