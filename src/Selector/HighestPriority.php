<?php

namespace ByJG\StateMachine\Selector;

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionSelectorInterface;

/**
 * The transition with the highest priority among those that accepted the data wins.
 *
 * This exists so that the tie-break stops being "whichever line was typed first". A machine
 * built by FiniteStateMachine::fromDefinition() takes its declaration order from the order of
 * entries in a YAML file, which makes reordering the file — or merging two of them — a change
 * in behaviour that nothing in the file admits to. A priority says out loud what the file
 * otherwise says by accident.
 *
 * Priority defaults to 0, so transitions that never declare one are all equal and the tie
 * falls through to the tie-breaker.
 *
 * The tie-breaker is itself a selector, which is what makes the two policies composable:
 * `new HighestPriority()` settles ties by declaration order, while
 * `new HighestPriority(new RejectAmbiguous())` accepts a stated priority and rejects an
 * accidental tie.
 */
class HighestPriority implements TransitionSelectorInterface
{
    private TransitionSelectorInterface $tieBreaker;

    /**
     * @param TransitionSelectorInterface|null $tieBreaker Decides between transitions sharing
     *                                                     the highest priority. Defaults to
     *                                                     Selector\FirstDeclared.
     */
    public function __construct(?TransitionSelectorInterface $tieBreaker = null)
    {
        $this->tieBreaker = $tieBreaker ?? new FirstDeclared();
    }

    #[\Override]
    public function select(State $from, iterable $matching, ?array $data): ?Transition
    {
        $highest = null;
        $matched = [];

        // Every condition has to run: the highest priority is not known until the last one has
        foreach ($matching as $transition) {
            $priority = $transition->getPriority();

            if (is_null($highest) || $priority > $highest) {
                $highest = $priority;
                $matched = [];
            }

            if ($priority === $highest) {
                $matched[] = $transition;
            }
        }

        if (count($matched) < 2) {
            return $matched[0] ?? null;
        }

        return $this->tieBreaker->select($from, $matched, $data);
    }
}
