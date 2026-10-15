<?php

namespace Tests;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionConditionInterface;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Letter;
use Tests\Fixture\Stock;

class FiniteStateMachineTest extends TestCase
{
    public function testCanTransition(): void
    {
        $stA = Letter::A;
        $stB = Letter::B;
        $stC = Letter::C;
        $stD = Letter::D;

        $transitionAB = new Transition($stA, $stB);
        $transitionAC = new Transition($stA, $stC);

        $condition = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return !is_null($data);
            }
        };
        $transitionBD = new Transition($stB, $stD, $condition);

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
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
            Letter::class,
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
        $stA = Letter::A;
        $stB = Letter::B;
        $stC = Letter::C;
        $stD = Letter::D;

        // A case and the string it corresponds to name the same state, whatever its case
        $this->assertEquals('A', $stateMachine->state(Letter::A)->getState());
        $this->assertEquals('B', $stateMachine->state('B')->getState());
        $this->assertEquals('C', $stateMachine->state('c')->getState());
        $this->assertEquals('D', $stateMachine->state('d')->getState());

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
        $stInitial = Stock::Start;
        $stInStock = Stock::InStock;
        $stLastUnits = Stock::LastUnits;
        $stOutOfStock = Stock::OutOfStock;

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

        $stateMachine = FiniteStateMachine::createMachine(Stock::class)
            ->addTransition($transitionInStock)
            ->addTransition($transitionLastUnits)
            ->addTransition($transitionOutOfStock);

        $this->assertEquals(
            $stLastUnits->value,
            $stateMachine->autoTransitionFrom($stInitial, ["qty" => 10, "min_stock" => 20])->getState()
        );
        $this->assertEquals(
            $stInStock->value,
            $stateMachine->autoTransitionFrom($stInitial, ["qty" => 30, "min_stock" => 20])->getState()
        );
        $this->assertEquals(
            $stOutOfStock->value,
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
        $stLastUnits = Stock::LastUnits;
        $stOutOfStock = Stock::OutOfStock;

        $stNotRequested = Stock::NotRequested;
        $stRequested = Stock::RequestedResupply;
        $stResupplied = Stock::Resupplied;
        $stUnavailable = Stock::Unavailable;

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

        $stateMachine = FiniteStateMachine::createMachine(Stock::class)
            ->addTransitions($transitionNotRequested)
            ->addTransitions($transitionRequested)
            ->addTransitions($transitionResupplied)
            ->addTransitions($transitionUnavailable);

        $this->assertEquals(
            $stNotRequested->value,
            $stateMachine->autoTransitionFrom($stLastUnits, [])->getState()
        );
        $this->assertEquals(
            $stRequested->value,
            $stateMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10])->getState()
        );
        $this->assertEquals(
            $stResupplied->value,
            $stateMachine->autoTransitionFrom(
                $stLastUnits,
                ["invoice_number" => 10, "fulfilment_number" => 50]
            )->getState()
        );
        $this->assertEquals(
            $stUnavailable->value,
            $stateMachine->autoTransitionFrom($stLastUnits, ["status" => "DNB"])->getState()
        );
    }

    /**
     * A state returned by autoTransitionFrom() carries the data used to validate the
     * transition. isInitialState()/isFinalState() must answer about the state itself,
     * regardless of the data attached to it.
     */
    public function testInitialAndFinalStateIgnoreAttachedData(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(
            Letter::class,
            [
                ["A", "B"],
                ["B", "C"],
            ]
        );

        $stA = $stateMachine->state(Letter::A);
        $stB = $stateMachine->autoTransitionFrom($stA, ["qty" => 10]);

        // Sanity: we got B and it is carrying the data
        $this->assertEquals("B", $stB->getState());
        $this->assertEquals(["qty" => 10], $stB->getData());

        // B has an inbound (A->B) and an outbound (B->C) transition
        $this->assertFalse($stateMachine->isInitialState($stB));
        $this->assertFalse($stateMachine->isFinalState($stB));

        // Same question, same answers, when asked with the stored (dataless) instance
        $this->assertFalse($stateMachine->isInitialState($stateMachine->state('B')));
        $this->assertFalse($stateMachine->isFinalState($stateMachine->state('B')));

        // C is reached with data and is genuinely final
        $stC = $stateMachine->autoTransitionFrom($stB, ["qty" => 10]);
        $this->assertEquals("C", $stC->getState());
        $this->assertFalse($stateMachine->isInitialState($stC));
        $this->assertTrue($stateMachine->isFinalState($stC));

        // A carrying data attached by the caller is genuinely initial
        $detachedA = $stateMachine->state(Letter::A);
        $detachedA->setData(["qty" => 10]);
        $this->assertTrue($stateMachine->isInitialState($detachedA));
        $this->assertFalse($stateMachine->isFinalState($detachedA));
    }

    /**
     * The states held by the machine must not be reachable for mutation by the caller,
     * otherwise setting data on a returned state corrupts the machine definition.
     */
    public function testMachineStatesAreNotMutableByTheCaller(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(
            Letter::class,
            [
                ["A", "B"],
            ]
        );

        // Leak path 1: the state() accessor
        $stateMachine->state('A')->setData(["leaked" => true]);
        $this->assertNull($stateMachine->state('A')->getData());

        // Leak path 2: the transition accessors
        $transition = $stateMachine->getTransition($stateMachine->state('A'), $stateMachine->state('B'));
        $transition->getCurrentState()->setData(["leaked" => true]);
        $this->assertNull($stateMachine->state('A')->getData());
        $this->assertNull($transition->getCurrentState()->getData());

        // The machine still behaves as declared
        $this->assertTrue($stateMachine->isInitialState($stateMachine->state('A')));
        $this->assertTrue($stateMachine->isFinalState($stateMachine->state('B')));
        $this->assertTrue($stateMachine->canTransition($stateMachine->state('A'), $stateMachine->state('B')));
    }

    /**
     * Two transitions between the same pair of states are a declaration error: the
     * second one used to silently overwrite the first, discarding its condition.
     */
    public function testDuplicatedTransitionIsRejected(): void
    {
        $never = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return false;
            }
        };

        $stA = Letter::A;
        $stB = Letter::B;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(new Transition($stA, $stB, $never));

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("A transition A -> B is already defined");

        $stateMachine->addTransition(new Transition($stA, $stB));
    }

    /**
     * Builds a machine where "invoice_number + status" satisfies both conditions.
     */
    protected function ambiguousMachine(bool $requestedFirst): FiniteStateMachine
    {
        $requested = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data["invoice_number"]) && !isset($data["fulfilment_number"]);
            }
        };

        $unavailable = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return isset($data["status"]);
            }
        };

        $stFrom = Stock::LastUnits;
        $stRequested = Stock::RequestedResupply;
        $stUnavailable = Stock::Unavailable;

        $transitions = [
            Transition::create($stFrom, $stRequested, $requested),
            Transition::create($stFrom, $stUnavailable, $unavailable),
        ];

        return FiniteStateMachine::createMachine(Stock::class)
            ->addTransitions($requestedFirst ? $transitions : array_reverse($transitions));
    }

    /**
     * By default the first transition declared that matches wins. This is a documented
     * guarantee, so it is asserted rather than left to chance.
     */
    public function testAutoTransitionUsesFirstDeclaredMatch(): void
    {
        $data = ["invoice_number" => 10, "status" => "DNB"];
        $stFrom = Stock::LastUnits;

        $this->assertEquals(
            "REQUESTED_RESUPPLY",
            $this->ambiguousMachine(true)->autoTransitionFrom($stFrom, $data)->getState()
        );

        $this->assertEquals(
            "UNAVAILABLE",
            $this->ambiguousMachine(false)->autoTransitionFrom($stFrom, $data)->getState()
        );
    }

    public function testAutoTransitionDetectsAmbiguityWhenEnabled(): void
    {
        $stFrom = Stock::LastUnits;
        $stateMachine = $this->ambiguousMachine(true)->throwErrorIfAmbiguousTransition();

        // Data matching a single condition still transitions normally
        $this->assertEquals(
            "REQUESTED_RESUPPLY",
            $stateMachine->autoTransitionFrom($stFrom, ["invoice_number" => 10])->getState()
        );
        $this->assertEquals(
            "UNAVAILABLE",
            $stateMachine->autoTransitionFrom($stFrom, ["status" => "DNB"])->getState()
        );

        // Data matching no condition still returns null
        $this->assertNull($stateMachine->autoTransitionFrom($stFrom, ["fulfilment_number" => 1]));

        // Data matching both is now reported instead of silently resolved
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            "Ambiguous transition from LAST_UNITS: the data provided matches REQUESTED_RESUPPLY, UNAVAILABLE"
        );
        $stateMachine->autoTransitionFrom($stFrom, ["invoice_number" => 10, "status" => "DNB"]);
    }

    public function testDuplicatedTransitionIsRejectedInSimpleMode(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("A transition A -> B is already defined");

        FiniteStateMachine::createMachine(
            Letter::class,
            [
                ["A", "B"],
                ["A", "B"],
            ]
        );
    }
}
