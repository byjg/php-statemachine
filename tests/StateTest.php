<?php

namespace Tests;

use ByJG\StateMachine\State;
use ByJG\StateMachine\StateActionInterface;
use PHPUnit\Framework\TestCase;

class StateTest extends TestCase
{

    public function testState(): void
    {
        $state = new State('MY_STATE');

        // Sanity Test
        $this->assertEquals('MY_STATE', $state->getState());
        $this->assertNull($state->getData());


        // Nothing should happen
        $state->process();
    }

    public function testStateAction(): void
    {
        $varControl = null;

        $action = new class($varControl) implements StateActionInterface {
            private $varControl;

            public function __construct(&$varControl) {
                $this->varControl = &$varControl;
            }

            #[\Override]
            public function execute(?array $data): void {
                $this->varControl = $data;
            }
        };

        $state = new State('MY_STATE', $action);

        // Sanity Tests
        $this->assertEquals('MY_STATE', $state->getState());
        $this->assertNull($varControl);

        // Call process wont change anything because there is no data
        $state->process();
        $this->assertNull($varControl);

        // After set, should get the proper value.
        $state->setData(['value']);
        $this->assertEquals(['value'], $state->getData());
        $state->process();
        $this->assertEquals(['value'], $varControl);
    }
}
