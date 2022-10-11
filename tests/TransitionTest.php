<?php

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use PHPUnit\Framework\TestCase;

require_once "vendor/autoload.php";

class TransitionTest extends TestCase
{
    public function testTransition()
    {
        $state1 = new State('1');
        $state2 = new State('2');

        $transition = Transition::create($state1, $state2);

        // Sanity Test
        $this->assertSame($state1, $transition->getCurrentState());
        $this->assertEquals($state2, $transition->getDesiredState());  // The state is equal
        $this->assertNotSame($state2, $transition->getDesiredState()); // However, they aren't the same object
        $this->assertNull($transition->getDesiredState()->getData());

        // Get State with Data
        $this->assertEquals(['data'], $transition->getDesiredState(['data'])->getData());
    }

    public function testRunTransition()
    {
        $state1 = new State('1');
        $state2 = new State('2');

        $transition = Transition::create($state1, $state2, function ($data) { return isset($data['key']); });

        $this->assertTrue($transition->runTransitionFunction(['key'=>'1']));
        $this->assertFalse($transition->runTransitionFunction(['key_sample'=>'1']));
    }

    public function testMultipleTransition()
    {
        $state1 = new State('1');
        $state2 = new State('2');
        $state3 = new State('3');

        $transitionList = Transition::createMultiple([$state1, $state2], $state3);

        $this->assertEquals(2, count($transitionList));
        
        $this->assertEquals($state1, $transitionList[0]->getCurrentState());
        $this->assertEquals($state3, $transitionList[0]->getDesiredState());

        $this->assertEquals($state2, $transitionList[1]->getCurrentState());
        $this->assertEquals($state3, $transitionList[1]->getDesiredState());
    }
}
