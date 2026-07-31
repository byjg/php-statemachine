<?php

namespace Tests\Fixture;

use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;

/**
 * Records every transition processed through it.
 *
 * The log is static because the machine builds this object itself when the definition
 * refers to it by name, so the test has no reference to hand it one.
 */
class RecordingAction implements TransitionActionInterface
{
    /** @var string[] */
    public static array $log = [];

    public static function reset(): void
    {
        self::$log = [];
    }

    #[\Override]
    public function execute(State $from, State $to, ?array $data): void
    {
        self::$log[] = "{$from}->{$to}";
    }
}
