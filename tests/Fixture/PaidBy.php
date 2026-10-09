<?php

namespace Tests\Fixture;

use ByJG\StateMachine\TransitionConditionInterface;

/**
 * Selects one of several moves between the same two states: the data says which way the money
 * arrived, and only the matching transition is taken.
 */
class PaidBy implements TransitionConditionInterface
{
    public function __construct(private readonly string $method)
    {
    }

    #[\Override]
    public function canTransition(?array $data): bool
    {
        return ($data["method"] ?? null) === $this->method;
    }
}
