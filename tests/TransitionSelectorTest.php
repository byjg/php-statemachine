<?php

namespace Tests;

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\Selector\FirstDeclared;
use ByJG\StateMachine\Selector\HighestPriority;
use ByJG\StateMachine\Selector\RejectAmbiguous;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionException;
use ByJG\StateMachine\TransitionSelectorInterface;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\CountingCondition;
use Tests\Fixture\Stock;

/**
 * Covers which move wins when the data satisfies more than one of them.
 *
 * The conditions here are deliberately all-true or all-false: what is being tested is the
 * policy choosing between matches, not the matching itself.
 */
class TransitionSelectorTest extends TestCase
{
    /**
     * Three moves out of START, each with a condition whose answer is fixed and whose calls
     * are counted. The counts are what make the evaluation order observable.
     *
     * @param bool[] $answers
     * @return array{FiniteStateMachine, CountingCondition[]}
     */
    private function machineWith(array $answers, array $priorities = []): array
    {
        $targets = [Stock::LastUnits, Stock::InStock, Stock::OutOfStock];
        $conditions = [];
        $stateMachine = FiniteStateMachine::createMachine(Stock::class);

        foreach ($answers as $index => $answer) {
            $conditions[$index] = new CountingCondition($answer);
            $transition = Transition::create(Stock::Start, $targets[$index], $conditions[$index]);

            if (isset($priorities[$index])) {
                $transition = $transition->withPriority($priorities[$index]);
            }

            $stateMachine->addTransition($transition);
        }

        return [$stateMachine, $conditions];
    }

    public function testFirstDeclaredIsTheDefaultAndStopsAtTheFirstMatch(): void
    {
        [$stateMachine, $conditions] = $this->machineWith([true, true, true]);

        $this->assertEquals("LAST_UNITS", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));

        // The point of the lazy iterable: the moves declared after the winner are never asked
        $this->assertSame(1, $conditions[0]->calls);
        $this->assertSame(0, $conditions[1]->calls);
        $this->assertSame(0, $conditions[2]->calls);
    }

    public function testFirstDeclaredSkipsTheMovesThatDenyTheData(): void
    {
        [$stateMachine, $conditions] = $this->machineWith([false, true, true]);

        $this->assertEquals("IN_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));

        $this->assertSame(1, $conditions[0]->calls);
        $this->assertSame(1, $conditions[1]->calls);
        $this->assertSame(0, $conditions[2]->calls);
    }

    public function testSelectingFirstDeclaredExplicitlyMatchesTheDefault(): void
    {
        [$stateMachine, $conditions] = $this->machineWith([true, true, true]);
        $stateMachine->selectWith(new FirstDeclared());

        $this->assertEquals("LAST_UNITS", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
        $this->assertSame(0, $conditions[1]->calls);
    }

    public function testNoMatchReturnsNullWhateverTheSelector(): void
    {
        [$stateMachine, $conditions] = $this->machineWith([false, false, false]);
        $stateMachine->selectWith(new RejectAmbiguous());

        $this->assertNull($stateMachine->autoTransitionFrom(Stock::Start, []));
        $this->assertSame(1, $conditions[2]->calls);
    }

    public function testRejectAmbiguousEvaluatesEveryConditionAndThrows(): void
    {
        [$stateMachine, $conditions] = $this->machineWith([true, true, false]);
        $stateMachine->selectWith(new RejectAmbiguous());

        try {
            $stateMachine->autoTransitionFrom(Stock::Start, []);
            $this->fail("Expected an ambiguity to be reported");
        } catch (TransitionException $exception) {
            $this->assertEquals(
                "Ambiguous transition from __VOID__: the data provided matches LAST_UNITS, IN_STOCK",
                $exception->getMessage()
            );
        }

        // Unlike FirstDeclared, this one has to see them all before it can know
        $this->assertSame(1, $conditions[2]->calls);
    }

    public function testRejectAmbiguousTransitionsNormallyOnASingleMatch(): void
    {
        [$stateMachine] = $this->machineWith([false, true, false]);
        $stateMachine->selectWith(new RejectAmbiguous());

        $this->assertEquals("IN_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    /**
     * The shorthand and the object are the same policy, including the message.
     */
    public function testThrowErrorIfAmbiguousTransitionInstallsRejectAmbiguous(): void
    {
        [$stateMachine] = $this->machineWith([true, true, false]);
        $stateMachine->throwErrorIfAmbiguousTransition();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("Ambiguous transition from __VOID__");
        $stateMachine->autoTransitionFrom(Stock::Start, []);
    }

    public function testHighestPriorityWinsOverDeclarationOrder(): void
    {
        [$stateMachine] = $this->machineWith([true, true, true], [0 => 1, 1 => 10, 2 => 5]);
        $stateMachine->selectWith(new HighestPriority());

        $this->assertEquals("IN_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    /**
     * Priority ranks the moves that accepted the data. A higher-ranked move whose condition
     * said no is not a candidate at all, so it cannot outrank anything.
     */
    public function testHighestPriorityIgnoresTheMovesThatDenyTheData(): void
    {
        [$stateMachine] = $this->machineWith([true, false, true], [0 => 1, 1 => 10, 2 => 5]);
        $stateMachine->selectWith(new HighestPriority());

        $this->assertEquals("OUT_OF_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    public function testHighestPrioritySettlesATieByDeclarationOrder(): void
    {
        [$stateMachine] = $this->machineWith([true, true, true], [0 => 5, 1 => 5, 2 => 1]);
        $stateMachine->selectWith(new HighestPriority());

        $this->assertEquals("LAST_UNITS", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    /**
     * The composition the interface exists for: a stated order, and an unstated tie is an error.
     */
    public function testHighestPriorityDelegatesATieToItsTieBreaker(): void
    {
        [$stateMachine] = $this->machineWith([true, true, true], [0 => 5, 1 => 5, 2 => 1]);
        $stateMachine->selectWith(new HighestPriority(new RejectAmbiguous()));

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            "Ambiguous transition from __VOID__: the data provided matches LAST_UNITS, IN_STOCK"
        );
        $stateMachine->autoTransitionFrom(Stock::Start, []);
    }

    public function testHighestPriorityDoesNotConsultTheTieBreakerWhenThereIsNoTie(): void
    {
        [$stateMachine] = $this->machineWith([true, true, true], [0 => 5, 1 => 9, 2 => 1]);
        $stateMachine->selectWith(new HighestPriority(new RejectAmbiguous()));

        $this->assertEquals("IN_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    public function testPriorityDefaultsToZeroAndIsCarriedByACopy(): void
    {
        $transition = Transition::create(Stock::Start, Stock::InStock);
        $ranked = $transition->withPriority(7);

        $this->assertSame(0, $transition->getPriority());
        $this->assertSame(7, $ranked->getPriority());
        $this->assertNotSame($transition, $ranked);
        $this->assertEquals("IN_STOCK", (string)$ranked->getDesiredState());
    }

    public function testPriorityCanBeDeclaredInTheTransitionList(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Stock::class, [
            [Stock::Start, Stock::LastUnits, new CountingCondition(true), null, null, 1],
            [Stock::Start, Stock::InStock, new CountingCondition(true), null, null, 10],
        ])->selectWith(new HighestPriority());

        $this->assertSame(10, $stateMachine->getTransition(Stock::Start, Stock::InStock)->getPriority());
        $this->assertEquals("IN_STOCK", (string)$stateMachine->autoTransitionFrom(Stock::Start, []));
    }

    /**
     * A file has an order of its own, and this is what stops that order from deciding.
     */
    public function testPriorityCanBeDeclaredInAYamlDefinition(): void
    {
        $definition = Serialize::fromYaml((string)file_get_contents(__DIR__ . "/fixtures/priority.yaml"))->toArray();

        $stateMachine = FiniteStateMachine::fromDefinition($definition)
            ->selectWith(new HighestPriority());

        $this->assertSame(0, $stateMachine->getTransition(Stock::Start, Stock::LastUnits)->getPriority());
        $this->assertSame(10, $stateMachine->getTransition(Stock::Start, Stock::InStock)->getPriority());

        $reached = $stateMachine->autoTransitionFrom(Stock::Start, ["reviewer" => "ana"]);
        $this->assertEquals("IN_STOCK", (string)$reached);
    }

    public function testTheSelectorReceivesTheStateBeingLeftAndTheData(): void
    {
        $seen = new \stdClass();
        $seen->from = null;
        $seen->data = null;

        $selector = new class ($seen) implements TransitionSelectorInterface {
            public function __construct(private readonly \stdClass $seen)
            {
            }

            #[\Override]
            public function select(State $from, iterable $matching, ?array $data): ?Transition
            {
                $this->seen->from = (string)$from;
                $this->seen->data = $data;

                foreach ($matching as $transition) {
                    return $transition;
                }

                return null;
            }
        };

        [$stateMachine] = $this->machineWith([true, true, true]);
        $stateMachine->selectWith($selector);

        $stateMachine->autoTransitionFrom(Stock::Start, ["qty" => 3]);

        $this->assertEquals("__VOID__", $seen->from);
        $this->assertEquals(["qty" => 3], $seen->data);
    }

    public function testASelectorReturningNullThrowsWhenTheMachineIsStrict(): void
    {
        $selector = new class implements TransitionSelectorInterface {
            #[\Override]
            public function select(State $from, iterable $matching, ?array $data): ?Transition
            {
                return null;
            }
        };

        [$stateMachine] = $this->machineWith([true, true, true]);
        $stateMachine->selectWith($selector)->throwErrorIfCannotTransition();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("There is not possible transitions from __VOID__ with the data provided");
        $stateMachine->autoTransitionFrom(Stock::Start, []);
    }

    /**
     * The invariant the interface is bounded by: a selector picks between legal moves and
     * cannot invent one, so a transition the machine never offered is refused rather than taken.
     */
    public function testATransitionTheSelectorWasNotOfferedIsRefused(): void
    {
        $foreign = Transition::create(Stock::Start, Stock::Unavailable);

        $selector = new class ($foreign) implements TransitionSelectorInterface {
            public function __construct(private readonly Transition $foreign)
            {
            }

            #[\Override]
            public function select(State $from, iterable $matching, ?array $data): ?Transition
            {
                return $this->foreign;
            }
        };

        [$stateMachine] = $this->machineWith([true, true, true]);
        $stateMachine->selectWith($selector);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("chose __VOID__ -> UNAVAILABLE, which is not one of the transitions");
        $stateMachine->autoTransitionFrom(Stock::Start, []);
    }

    /**
     * A denied move is not offered, so returning it is the same mistake as returning a
     * foreign one — and the one most likely to be made by accident.
     */
    public function testATransitionWhoseConditionDeniedTheDataIsRefused(): void
    {
        $denied = Transition::create(Stock::Start, Stock::Unavailable, new CountingCondition(false));

        $selector = new class ($denied) implements TransitionSelectorInterface {
            public function __construct(private readonly Transition $denied)
            {
            }

            #[\Override]
            public function select(State $from, iterable $matching, ?array $data): ?Transition
            {
                foreach ($matching as $transition) {
                    // drain, then ignore what was offered
                }

                return $this->denied;
            }
        };

        [$stateMachine] = $this->machineWith([true, true, true]);
        $stateMachine->addTransition($denied)->selectWith($selector);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("A selector must return a transition it received, or null.");
        $stateMachine->autoTransitionFrom(Stock::Start, []);
    }
}
