<?php

namespace Tests;

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionActionInterface;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{

    public function testState(): void
    {
        $state = new State('MY_STATE');

        // Sanity Test
        $this->assertEquals('MY_STATE', $state->getState());
        $this->assertNull($state->getData());
        $this->assertNull($state->getPreviousState());

        // Nothing should happen
        $state->process();
    }

    public function testStateIsCaseInsensitive(): void
    {
        $this->assertEquals('MY_STATE', (new State('my_state'))->getState());
        $this->assertEquals('MY_STATE', (string)new State('My_State'));
    }

    /**
     * A state that was not produced by a transition has nothing to run.
     */
    public function testProcessIsNoOpWithoutATransition(): void
    {
        $state = new State('MY_STATE');
        $state->setData(['value']);

        $this->assertEquals(['value'], $state->getData());
        $this->assertNull($state->getPreviousState());

        // No transition was taken, so there is no action to run
        $state->process();
    }

    public function testProcessRunsTheActionOfTheTransitionTaken(): void
    {
        $received = [];

        $action = new class($received) implements TransitionActionInterface {
            private array $received;

            public function __construct(array &$received) {
                $this->received = &$received;
            }

            #[\Override]
            public function execute(State $from, State $to, ?array $data): void {
                $this->received = [
                    'from' => $from->getState(),
                    'to' => $to->getState(),
                    'data' => $data,
                ];
            }
        };

        $state = new State('TO_STATE');
        $state->setData(['value']);
        $state->arrivedThrough(new Transition('FROM_STATE', 'TO_STATE', null, $action));

        $this->assertEquals('FROM_STATE', $state->getPreviousState()->getState());
        $this->assertEquals([], $received);

        $state->process();

        $this->assertEquals(
            ['from' => 'FROM_STATE', 'to' => 'TO_STATE', 'data' => ['value']],
            $received
        );
    }

    /**
     * A transition without an action is legal; arriving through it must not blow up.
     */
    public function testProcessIsNoOpWhenTheTransitionHasNoAction(): void
    {
        $state = new State('TO_STATE');
        $state->arrivedThrough(new Transition('FROM_STATE', 'TO_STATE'));

        $this->assertEquals('FROM_STATE', $state->getPreviousState()->getState());

        $state->process();
    }
}
