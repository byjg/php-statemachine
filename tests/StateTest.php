<?php

use ByJG\StateMachine\State;
use PHPUnit\Framework\TestCase;

require_once "vendor/autoload.php";

class StateTest extends TestCase
{

    public function testState()
    {
        $state = new State('MY_STATE');

        // Sanity Test
        $this->assertEquals('MY_STATE', $state->getState());
        $this->assertNull($state->getData());
        
        
        // Nothing should happen
        $state->process();
    }

    public function testStateClosure()
    {
        $varControl = null;

        $state = new State('MY_STATE', function ($data) use (&$varControl) {$varControl = $data;});

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
