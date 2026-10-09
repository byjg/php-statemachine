<?php

namespace Tests\Fixture;

use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;

class CapturePreAuth implements TransitionActionInterface
{
    #[\Override]
    public function execute(State $from, State $to, ?array $data): void
    {
        RouteLog::$entries[] = "card:{$from}->{$to}";
    }
}
