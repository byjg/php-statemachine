<?php

namespace Tests;

use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionConditionInterface;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Letter;

class TransitionTest extends TestCase
{
    public function testTransition(): void
    {
        $state1 = new State('1');
        $state2 = new State('2');

        $transition = Transition::create($state1, $state2);

        // Sanity Test
        // Both accessors hand back a copy, so the caller cannot mutate the transition
        $this->assertEquals($state1, $transition->getCurrentState());   // The state is equal
        $this->assertNotSame($state1, $transition->getCurrentState());  // However, they aren't the same object
        $this->assertEquals($state2, $transition->getDesiredState());   // The state is equal
        $this->assertNotSame($state2, $transition->getDesiredState());  // However, they aren't the same object
        $this->assertNull($transition->getDesiredState()->getData());

        // Get State with Data
        $this->assertEquals(['data'], $transition->getDesiredState(['data'])->getData());
    }

    public function testRunTransition(): void
    {
        $state1 = new State('1');
        $state2 = new State('2');

        $condition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data['key']);
            }
        };

        $transition = Transition::create($state1, $state2, $condition);

        $this->assertTrue($transition->runTransitionFunction(['key'=>'1']));
        $this->assertFalse($transition->runTransitionFunction(['key_sample'=>'1']));
    }

    public function testMultipleTransition(): void
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

    /**
     * A Transition names its ends the same way the machine does. On its own it has no enum to
     * check them against — it normalises the reference, and FiniteStateMachine::addTransition()
     * is what rejects a state the enum does not declare.
     */
    public function testEndsCanBeNamedByACaseOrAString(): void
    {
        $fromCase = Transition::create(Letter::A, Letter::B);
        $this->assertEquals('A', $fromCase->getCurrentState()->getState());
        $this->assertEquals('B', $fromCase->getDesiredState()->getState());

        $fromString = Transition::create('a', 'b');
        $this->assertEquals('A', $fromString->getCurrentState()->getState());
        $this->assertEquals('B', $fromString->getDesiredState()->getState());

        $fromState = Transition::create(new State('A'), new State('B'));
        $this->assertEquals('A', $fromState->getCurrentState()->getState());
        $this->assertEquals('B', $fromState->getDesiredState()->getState());
    }

    public function testMultipleTransitionAcceptsCases(): void
    {
        $transitionList = Transition::createMultiple([Letter::A, Letter::B], Letter::C);

        $this->assertCount(2, $transitionList);
        $this->assertEquals('A', $transitionList[0]->getCurrentState()->getState());
        $this->assertEquals('C', $transitionList[0]->getDesiredState()->getState());
        $this->assertEquals('B', $transitionList[1]->getCurrentState()->getState());
        $this->assertEquals('C', $transitionList[1]->getDesiredState()->getState());
    }
}
