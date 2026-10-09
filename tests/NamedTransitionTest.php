<?php

namespace Tests;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\PaidBy;
use Tests\Fixture\PaidByCard;
use Tests\Fixture\PaidByPix;
use Tests\Fixture\Payment;

/**
 * A pair of states does not always identify a move. DRAFT to PAID by PIX, by card and by bank
 * transfer are three moves with three conditions and three side effects, and collapsing them
 * into one transition would lose exactly the thing that distinguishes them.
 */
class NamedTransitionTest extends TestCase
{
    /** @var string[] */
    private array $log = [];

    private function recorder(string $label): TransitionActionInterface
    {
        return new class($this->log, $label) implements TransitionActionInterface {
            /** @var string[] */
            private array $log;

            public function __construct(array &$log, private readonly string $label)
            {
                $this->log = &$log;
            }

            #[\Override]
            public function execute(State $from, State $to, ?array $data): void
            {
                $this->log[] = "{$this->label}:{$from}->{$to}";
            }
        };
    }

    private function machine(): FiniteStateMachine
    {
        return FiniteStateMachine::createMachine(Payment::class)
            ->addTransition(Transition::named(
                'PIX',
                Payment::Draft,
                Payment::Paid,
                new PaidBy('PIX'),
                $this->recorder('pix')
            ))
            ->addTransition(Transition::named(
                'CARD',
                Payment::Draft,
                Payment::Paid,
                new PaidBy('CARD'),
                $this->recorder('card')
            ))
            ->addTransition(Transition::named(
                'ETF',
                Payment::Draft,
                Payment::Paid,
                new PaidBy('ETF'),
                $this->recorder('etf')
            ));
    }

    protected function setUp(): void
    {
        $this->log = [];
    }

    public function testSeveralNamedMovesMayJoinTheSameTwoStates(): void
    {
        $stateMachine = $this->machine();

        $this->assertCount(3, $stateMachine->possibleTransitions(Payment::Draft));
        $this->assertEquals(
            ['PIX', 'CARD', 'ETF'],
            array_map(fn (Transition $t): string => $t->getName(), $stateMachine->possibleTransitions(Payment::Draft))
        );
    }

    /**
     * The point of the whole feature: the data picks the move, and only that move's side effect
     * runs, even though all three end in the same state.
     */
    public function testTheDataSelectsWhichOfTheMovesIsTaken(): void
    {
        $stateMachine = $this->machine();

        $paid = $stateMachine->autoTransitionFrom(Payment::Draft, ['method' => 'CARD']);

        $this->assertEquals('PAID', $paid->getState());
        $this->assertEquals('CARD', $paid->getTransitionName());

        $paid->process();
        $this->assertEquals(['card:DRAFT->PAID'], $this->log);
    }

    public function testEachRouteRunsItsOwnAction(): void
    {
        $stateMachine = $this->machine();

        foreach (['PIX', 'CARD', 'ETF'] as $method) {
            $stateMachine->autoTransitionFrom(Payment::Draft, ['method' => $method])?->process();
        }

        $this->assertEquals(
            ['pix:DRAFT->PAID', 'card:DRAFT->PAID', 'etf:DRAFT->PAID'],
            $this->log
        );
    }

    public function testDataMatchingNoRouteStillTransitionsNowhere(): void
    {
        $this->assertNull($this->machine()->autoTransitionFrom(Payment::Draft, ['method' => 'CHEQUE']));
        $this->assertEquals([], $this->log);
    }

    /**
     * The name is what tells apart the routes into a state, so it survives the move and can be
     * persisted next to the state itself.
     */
    public function testAnUnnamedMoveReportsAnEmptyName(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Payment::class, [
            [Payment::Draft, Payment::Cancelled],
        ]);

        $cancelled = $stateMachine->autoTransitionFrom(Payment::Draft, []);

        $this->assertEquals('CANCELLED', $cancelled->getState());
        $this->assertEquals('', $cancelled->getTransitionName());
    }

    public function testAStateNotReachedByATransitionHasNoTransitionName(): void
    {
        $this->assertNull($this->machine()->state(Payment::Draft)->getTransitionName());
    }

    // ---- naming an explicit move

    public function testAnExplicitMoveIsNamedToPickTheRoute(): void
    {
        $stateMachine = $this->machine();
        $data = ['method' => 'PIX'];

        $this->assertTrue($stateMachine->canTransition(Payment::Draft, Payment::Paid, $data, 'PIX'));
        $this->assertFalse($stateMachine->canTransition(Payment::Draft, Payment::Paid, $data, 'CARD'));

        $paid = $stateMachine->transition(Payment::Draft, Payment::Paid, $data, 'PIX');
        $this->assertEquals('PIX', $paid->getTransitionName());

        $paid->process();
        $this->assertEquals(['pix:DRAFT->PAID'], $this->log);
    }

    public function testNamesAreMatchedRegardlessOfCase(): void
    {
        $stateMachine = $this->machine();

        $this->assertEquals('PIX', $stateMachine->getTransition(Payment::Draft, Payment::Paid, 'pix')->getName());
        $this->assertNull($stateMachine->getTransition(Payment::Draft, Payment::Paid, 'BOLETO'));
    }

    /**
     * Once a pair of states is joined more than once, the pair alone no longer identifies a move,
     * and asking as though it did is a question with no answer.
     */
    public function testAskingWithoutANameWhenThereAreSeveralIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            'There is more than one transition from DRAFT to PAID: PIX, CARD, ETF. Name the one you mean.'
        );

        $this->machine()->getTransition(Payment::Draft, Payment::Paid);
    }

    /**
     * The pair still identifies the move while it is the only one, so nothing that was written
     * before names existed has to change.
     */
    public function testTheNameIsOptionalWhileThePairIsUnique(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Payment::class, [
            [Payment::Draft, Payment::Paid],
        ]);

        $this->assertNotNull($stateMachine->getTransition(Payment::Draft, Payment::Paid));
        $this->assertTrue($stateMachine->canTransition(Payment::Draft, Payment::Paid));
    }

    // ---- declaration errors

    public function testTwoMovesWithTheSameNameBetweenTheSameStatesAreRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('A transition DRAFT -> PAID (PIX) is already defined');

        FiniteStateMachine::createMachine(Payment::class)
            ->addTransition(Transition::named('PIX', Payment::Draft, Payment::Paid))
            ->addTransition(Transition::named('PIX', Payment::Draft, Payment::Paid));
    }

    public function testTwoUnnamedMovesBetweenTheSameStatesAreStillRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('A transition DRAFT -> PAID is already defined');

        FiniteStateMachine::createMachine(Payment::class, [
            [Payment::Draft, Payment::Paid],
            [Payment::Draft, Payment::Paid],
        ]);
    }

    /**
     * Two routes whose conditions can both be true is the same declaration mistake it always was;
     * the report now says which routes, since naming only the destination would say "PAID, PAID".
     */
    public function testAmbiguityBetweenTwoNamedRoutesNamesThem(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Payment::class)
            ->addTransition(Transition::named('PIX', Payment::Draft, Payment::Paid))
            ->addTransition(Transition::named('CARD', Payment::Draft, Payment::Paid))
            ->throwErrorIfAmbiguousTransition();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            'Ambiguous transition from DRAFT: the data provided matches PAID (PIX), PAID (CARD)'
        );

        $stateMachine->autoTransitionFrom(Payment::Draft, []);
    }

    // ---- the declarative form

    public function testNamedMovesCanBeDeclaredInADefinition(): void
    {
        $stateMachine = FiniteStateMachine::fromDefinition([
            'enum' => Payment::class,
            'transitions' => [
                ['name' => 'PIX', 'from' => 'DRAFT', 'to' => 'PAID', 'condition' => PaidByPix::class],
                ['name' => 'CARD', 'from' => 'DRAFT', 'to' => 'PAID', 'condition' => PaidByCard::class],
                ['from' => 'DRAFT', 'to' => 'CANCELLED'],
            ],
        ]);

        $this->assertCount(3, $stateMachine->possibleTransitions(Payment::Draft));

        $paid = $stateMachine->autoTransitionFrom(Payment::Draft, ['method' => 'CARD']);
        $this->assertEquals('PAID', $paid->getState());
        $this->assertEquals('CARD', $paid->getTransitionName());
    }

    /**
     * createMultiple() shares one name across the transitions it builds, which is legal because
     * they start from different states.
     */
    public function testCreateMultipleSharesTheNameAcrossOrigins(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Payment::class)
            ->addTransitions(Transition::createMultiple(
                [Payment::Draft, Payment::Paid],
                Payment::Cancelled,
                null,
                null,
                'REFUSED'
            ));

        $this->assertEquals(
            'REFUSED',
            $stateMachine->getTransition(Payment::Draft, Payment::Cancelled)->getName()
        );
        $this->assertEquals(
            'REFUSED',
            $stateMachine->getTransition(Payment::Paid, Payment::Cancelled)->getName()
        );
    }
}
