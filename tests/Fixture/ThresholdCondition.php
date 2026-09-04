<?php

namespace Tests\Fixture;

use ByJG\StateMachine\TransitionConditionInterface;

/**
 * A condition the machine CANNOT build on its own: the threshold has to come from somewhere.
 *
 * This is what the resolver exists for — a container knows how to supply the argument, the
 * state machine does not.
 */
class ThresholdCondition implements TransitionConditionInterface
{
    public function __construct(private readonly int $minimum)
    {
    }

    #[\Override]
    public function canTransition(?array $data): bool
    {
        return ($data["qty"] ?? 0) >= $this->minimum;
    }
}
