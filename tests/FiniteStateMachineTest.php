<?php

namespace Tests;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionConditionInterface;
use PHPUnit\Framework\TestCase;

class FiniteStateMachineTest extends TestCase
{
    public function testCanTransition(): void
    {
        $stA = new State("A");
        $stB = new State("B");
        $stC = new State("C");
        $stD = new State("D");

        $transitionAB = new Transition($stA, $stB);
        $transitionAC = new Transition($stA, $stC);

        $condition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return !is_null($data);
            }
        };
        $transitionBD = new Transition($stB, $stD, $condition);

        $stateMachine = FiniteStateMachine::createMachine()
            //    ->throwErrorIfCannotTransition()
            ->addTransition($transitionAB)
            ->addTransitions([$transitionAC, $transitionBD]);

        // Assertions
        $this->assertEquals([$transitionAB, $transitionAC], $stateMachine->possibleTransitions($stA));
        $this->assertEquals([$transitionBD], $stateMachine->possibleTransitions($stB));
        $this->assertEquals([], $stateMachine->possibleTransitions($stC));
        $this->assertEquals([], $stateMachine->possibleTransitions($stD));

        $this->assertEquals($transitionBD, $stateMachine->getTransition($stB, $stD));
        $this->assertNull($stateMachine->getTransition($stB, $stC));

        $this->canTransitionAssertions($stateMachine);
    }

    public function testCanTransitionSimpleMode(): void
    {
        $condition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return !is_null($data);
            }
        };

        $stateMachine = FiniteStateMachine::createMachine(
            [
                ["A", "B"],
                ["A", "C"],
                ["B", "D", $condition]
            ]
            );

        $this->canTransitionAssertions($stateMachine);
    }

    protected function canTransitionAssertions($stateMachine): void
    {
        $stA = new State("A");
        $stB = new State("B");
        $stC = new State("C");
        $stD = new State("D");

        $this->assertEquals($stA, $stateMachine->state('A'));
        $this->assertEquals($stB, $stateMachine->state('B'));
        $this->assertEquals($stC, $stateMachine->state('C'));
        $this->assertEquals($stD, $stateMachine->state('D'));
        $this->assertNull($stateMachine->state('NO'));
        $this->assertEquals($stA, $stateMachine->state('a'));
        $this->assertEquals($stB, $stateMachine->state('b'));
        $this->assertEquals($stC, $stateMachine->state('c'));
        $this->assertEquals($stD, $stateMachine->state('d'));



        $this->assertTrue($stateMachine->canTransition($stA, $stateMachine->state('B')));
        $this->assertTrue($stateMachine->canTransition($stA, $stC));
        $this->assertFalse($stateMachine->canTransition($stA, $stD));
        $this->assertFalse($stateMachine->canTransition($stB, $stA));
        $this->assertFalse($stateMachine->canTransition($stB, $stD));
        $this->assertTrue($stateMachine->canTransition($stB, $stD, ["some_info"]));
        $this->assertFalse($stateMachine->canTransition($stC, $stD));

        $this->assertTrue($stateMachine->isInitialState($stA));
        $this->assertFalse($stateMachine->isInitialState($stB));
        $this->assertFalse($stateMachine->isInitialState($stC));
        $this->assertFalse($stateMachine->isInitialState($stD));

        $this->assertFalse($stateMachine->isFinalState($stA));
        $this->assertFalse($stateMachine->isFinalState($stB));
        $this->assertTrue($stateMachine->isFinalState($stC));
        $this->assertTrue($stateMachine->isFinalState($stD));
    }

    public function testAutoTransition(): void
    {
        $stInitial = new State("__VOID__");
        $stInStock = new State("IN_STOCK");
        $stLastUnits = new State("LAST_UNITS");
        $stOutOfStock = new State("OUT_OF_STOCK");

        $inStockCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return $data["qty"] >= $data["min_stock"];
            }
        };

        $lastUnitsCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return $data["qty"] > 0 && $data["qty"] < $data["min_stock"];
            }
        };

        $outOfStockCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return $data["qty"] == 0;
            }
        };

        $transitionInStock = Transition::create($stInitial, $stInStock, $inStockCondition);
        $transitionLastUnits = Transition::create($stInitial, $stLastUnits, $lastUnitsCondition);
        $transitionOutOfStock = Transition::create($stInitial, $stOutOfStock, $outOfStockCondition);

        $stateMachine = FiniteStateMachine::createMachine()
            ->addTransition($transitionInStock)
            ->addTransition($transitionLastUnits)
            ->addTransition($transitionOutOfStock);

        $this->assertEquals(
            $stLastUnits->getState(),
            $stateMachine->autoTransitionFrom($stInitial, ["qty" => 10, "min_stock" => 20])->getState()
        );
        $this->assertEquals(
            $stInStock->getState(),
            $stateMachine->autoTransitionFrom($stInitial, ["qty" => 30, "min_stock" => 20])->getState()
        );
        $this->assertEquals(
            $stOutOfStock->getState(),
            $stateMachine->autoTransitionFrom($stInitial, ["qty" => 00, "min_stock" => 20])->getState()
        );

        // There is no transition from LastUnits to OutOfStock
        $this->assertEquals(
            null,
            $stateMachine->autoTransitionFrom($stLastUnits, ["qty" => 00, "min_stock" => 20])
        );
    }

    public function testAutoTransition_2(): void
    {
        $stLastUnits = new State("LAST_UNITS");
        $stOutOfStock = new State("OUT_OF_STOCK");

        $stNotRequested = new State("NOT_REQUESTED");
        $stRequested = new State("REQUESTED_RESUPPLY");
        $stResupplied = new State("RESUPPLIED");
        $stUnavailable = new State("UNAVAILABLE");

        $notRequestedCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return !isset($data["invoice_number"]) && !isset($data["status"]);
            }
        };

        $requestedCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data["invoice_number"]) && !isset($data["fulfilment_number"]);
            }
        };

        $resuppliedCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data["fulfilment_number"]);
            }
        };

        $unavailableCondition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data["status"]);
            }
        };

        $transitionNotRequested = Transition::createMultiple(
            [$stLastUnits, $stOutOfStock],
            $stNotRequested,
            $notRequestedCondition
        );

        $transitionRequested = Transition::createMultiple(
            [$stLastUnits, $stOutOfStock],
            $stRequested,
            $requestedCondition
        );

        $transitionResupplied = Transition::createMultiple(
            [$stLastUnits, $stOutOfStock, $stRequested],
            $stResupplied,
            $resuppliedCondition
        );

        $transitionUnavailable = Transition::createMultiple(
            [$stLastUnits, $stOutOfStock],
            $stUnavailable,
            $unavailableCondition
        );

        $stateMachine = FiniteStateMachine::createMachine()
            ->addTransitions($transitionNotRequested)
            ->addTransitions($transitionRequested)
            ->addTransitions($transitionResupplied)
            ->addTransitions($transitionUnavailable);

        $this->assertEquals(
            $stNotRequested->getState(),
            $stateMachine->autoTransitionFrom($stLastUnits, [])->getState()
        );
        $this->assertEquals(
            $stRequested->getState(),
            $stateMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10])->getState()
        );
        $this->assertEquals(
            $stResupplied->getState(),
            $stateMachine->autoTransitionFrom(
                $stLastUnits,
                ["invoice_number" => 10, "fulfilment_number" => 50]
            )->getState()
        );
        $this->assertEquals(
            $stUnavailable->getState(),
            $stateMachine->autoTransitionFrom($stLastUnits, ["status" => "DNB"])->getState()
        );
    }
}
