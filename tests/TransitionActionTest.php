<?php

namespace Tests;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionConditionInterface;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Letter;

class TransitionActionTest extends TestCase
{
    /**
     * @param string[] $log
     */
    protected function recorder(array &$log, string $label): TransitionActionInterface
    {
        return new class($log, $label) implements TransitionActionInterface {
            /** @var string[] */
            private array $log;
            private string $label;

            public function __construct(array &$log, string $label) {
                $this->log = &$log;
                $this->label = $label;
            }

            #[\Override]
            public function execute(State $from, State $to, ?array $data): void {
                $this->log[] = "{$this->label}:{$from}->{$to}";
            }
        };
    }

    /**
     * The reason this design exists: one C, reached from A and from B, behaving
     * differently without being split into C_VIA_A and C_VIA_B.
     */
    public function testActionRunsOnlyForTheTransitionActuallyTaken(): void
    {
        $log = [];
        $stA = Letter::A;
        $stB = Letter::B;
        $stC = Letter::C;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(Transition::create($stA, $stC, null, $this->recorder($log, "viaA")))
            ->addTransition(Transition::create($stB, $stC, null, $this->recorder($log, "viaB")));

        // Arriving from A
        $fromA = $stateMachine->autoTransitionFrom($stA, ["order" => 1]);
        $this->assertEquals("C", $fromA->getState());
        $this->assertEquals("A", $fromA->getPreviousState()->getState());

        $this->assertEquals([], $log);   // nothing runs until the caller processes
        $fromA->process();
        $this->assertEquals(["viaA:A->C"], $log);

        // Arriving at the very same state from B
        $log = [];
        $fromB = $stateMachine->autoTransitionFrom($stB, ["order" => 1]);
        $this->assertEquals("C", $fromB->getState());
        $this->assertEquals("B", $fromB->getPreviousState()->getState());

        $fromB->process();
        $this->assertEquals(["viaB:B->C"], $log);
    }

    /**
     * A single action object attached to every inbound transition: the path-independent
     * case, declared once. This is what replaces the old state-level action.
     */
    public function testOneActionSharedAcrossManyTransitions(): void
    {
        $log = [];
        $stA = Letter::A;
        $stB = Letter::B;
        $stC = Letter::C;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransitions(
                Transition::createMultiple([$stA, $stB], $stC, null, $this->recorder($log, "audit"))
            );

        $stateMachine->autoTransitionFrom($stA, [])->process();
        $stateMachine->autoTransitionFrom($stB, [])->process();

        // Same action object, correct endpoints reported for each transition
        $this->assertEquals(["audit:A->C", "audit:B->C"], $log);
    }

    public function testActionReceivesTheDataUsedToValidateTheTransition(): void
    {
        $seen = null;

        $action = new class($seen) implements TransitionActionInterface {
            private $seen;

            public function __construct(&$seen) {
                $this->seen = &$seen;
            }

            #[\Override]
            public function execute(State $from, State $to, ?array $data): void {
                $this->seen = ['data' => $data, 'toData' => $to->getData()];
            }
        };

        $stA = Letter::A;
        $stB = Letter::B;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(Transition::create($stA, $stB, null, $action));

        $stateMachine->autoTransitionFrom($stA, ["qty" => 7])->process();

        $this->assertEquals(["qty" => 7], $seen['data']);
        $this->assertEquals(["qty" => 7], $seen['toData']);
    }

    public function testExplicitTransitionStampsTheState(): void
    {
        $log = [];
        $stA = Letter::A;
        $stB = Letter::B;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(Transition::create($stA, $stB, null, $this->recorder($log, "explicit")));

        $next = $stateMachine->transition($stA, $stB, ["k" => "v"]);

        $this->assertEquals("B", $next->getState());
        $this->assertEquals("A", $next->getPreviousState()->getState());
        $this->assertEquals(["k" => "v"], $next->getData());

        $next->process();
        $this->assertEquals(["explicit:A->B"], $log);
    }

    public function testExplicitTransitionRespectsTheCondition(): void
    {
        $log = [];
        $never = new class implements TransitionConditionInterface {
            #[\Override]
            public function canTransition(?array $data): bool {
                return false;
            }
        };

        $stA = Letter::A;
        $stB = Letter::B;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(Transition::create($stA, $stB, $never, $this->recorder($log, "never")));

        $this->assertNull($stateMachine->transition($stA, $stB, ["k" => "v"]));
        $this->assertEquals([], $log);
    }

    public function testExplicitTransitionReturnsNullForAnUndeclaredTransition(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Letter::class, [["A", "B"]]);

        // Both ends are states of the machine; there is simply no transition between them
        $this->assertNull($stateMachine->transition(Letter::B, Letter::A));
        $this->assertNull($stateMachine->transition(Letter::A, Letter::C));
    }

    public function testExplicitTransitionThrowsWhenConfiguredTo(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Letter::class, [["A", "B"]])
            ->throwErrorIfCannotTransition();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("Cannot transition from B to A");

        $stateMachine->transition(Letter::B, Letter::A);
    }

    /**
     * A state handed out by the machine was not reached by anything, so it has no action.
     */
    public function testStateTakenFromTheMachineIsNotStamped(): void
    {
        $log = [];
        $stA = Letter::A;
        $stB = Letter::B;

        $stateMachine = FiniteStateMachine::createMachine(Letter::class)
            ->addTransition(Transition::create($stA, $stB, null, $this->recorder($log, "viaA")));

        $loose = $stateMachine->state('B');
        $this->assertNull($loose->getPreviousState());

        $loose->process();
        $this->assertEquals([], $log);
    }

    public function testCreateMachineAcceptsAnActionInTheFourthSlot(): void
    {
        $log = [];

        $stateMachine = FiniteStateMachine::createMachine(
            Letter::class,
            [
                ["A", "B", null, $this->recorder($log, "simple")],
            ]
        );

        $stateMachine->autoTransitionFrom($stateMachine->state('A'), [])->process();

        $this->assertEquals(["simple:A->B"], $log);
    }
}
