<?php

namespace Tests\Fixture;

use ByJG\StateMachine\TransitionConditionInterface;

/**
 * A condition the machine can build on its own, because it needs no constructor argument.
 */
class HasReviewer implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return !empty($data["reviewer"]);
    }
}
