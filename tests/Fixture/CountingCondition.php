<?php

namespace Tests\Fixture;

use ByJG\StateMachine\TransitionConditionInterface;

/**
 * A condition with a fixed answer that records how many times it was asked.
 *
 * The count is what makes the laziness of the selector observable: a selector that stops at
 * the first match leaves the conditions after it on zero calls.
 */
class CountingCondition implements TransitionConditionInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $answer)
    {
    }

    #[\Override]
    public function canTransition(?array $data): bool
    {
        $this->calls++;

        return $this->answer;
    }
}
