<?php

namespace ByJG\StateMachine;

interface StateActionInterface
{
    /**
     * Executes the state action with the provided data.
     *
     * @param array|null $data The data to be used in the state action
     * @return void
     */
    public function execute(?array $data): void;
}
