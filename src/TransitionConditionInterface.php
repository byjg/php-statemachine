<?php

namespace ByJG\StateMachine;

interface TransitionConditionInterface
{
    /**
     * Validates if the transition can occur based on the provided data.
     *
     * @param array|null $data The data to validate the transition condition
     * @return bool True if the transition is allowed, false otherwise
     */
    public function canTransition(?array $data): bool;
}
