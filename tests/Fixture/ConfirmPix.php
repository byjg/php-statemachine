<?php

namespace Tests\Fixture;

use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;

class ConfirmPix implements TransitionActionInterface
{
    #[\Override]
    public function execute(State $from, State $to, ?array $data): void
    {
        RouteLog::$entries[] = "pix:{$from}->{$to}";
    }
}
