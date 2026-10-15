<?php

namespace Tests;

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\Transition;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Payment;
use Tests\Fixture\RouteLog;

/**
 * Named transitions written the way they will actually be written: in a file.
 *
 * The PHP API and the definition format have to agree on the same rules, so these repeat the
 * NamedTransitionTest cases through YAML rather than through constructor calls.
 */
class NamedTransitionYamlTest extends TestCase
{
    protected function setUp(): void
    {
        RouteLog::reset();
    }

    private function load(string $file): FiniteStateMachine
    {
        return FiniteStateMachine::fromDefinition(
            Serialize::fromYaml((string)file_get_contents(__DIR__ . "/fixtures/{$file}"))->toArray()
        );
    }

    public function testTheFileDeclaresSeveralNamedMovesBetweenOnePair(): void
    {
        $stateMachine = $this->load('payment.yaml');

        $this->assertEquals(
            ['DRAFT -> PAID (PIX)', 'DRAFT -> PAID (CARD)', 'DRAFT -> CANCELLED'],
            array_map(
                fn (Transition $t): string => $t->describe(),
                $stateMachine->possibleTransitions(Payment::Draft)
            )
        );
    }

    /**
     * The condition named in the file picks the route, and the action named on that route is the
     * only one that runs — even though both routes end in PAID.
     */
    public function testTheDataSelectsTheRouteDeclaredInTheFile(): void
    {
        $stateMachine = $this->load('payment.yaml');

        $paid = $stateMachine->autoTransitionFrom(Payment::Draft, ['method' => 'CARD']);

        $this->assertEquals('PAID', $paid->getState());
        $this->assertEquals('CARD', $paid->getTransitionName());

        $paid->process();
        $this->assertEquals(['card:DRAFT->PAID'], RouteLog::$entries);
    }

    public function testEachRouteInTheFileRunsItsOwnAction(): void
    {
        $stateMachine = $this->load('payment.yaml');

        foreach (['PIX', 'CARD'] as $method) {
            $stateMachine->autoTransitionFrom(Payment::Draft, ['method' => $method])?->process();
        }

        $this->assertEquals(['pix:DRAFT->PAID', 'card:DRAFT->PAID'], RouteLog::$entries);
    }

    public function testAnUnnamedMoveInTheFileKeepsAnEmptyName(): void
    {
        $stateMachine = $this->load('payment.yaml');

        $cancelled = $stateMachine->transition(Payment::Draft, Payment::Cancelled);

        $this->assertEquals('CANCELLED', $cancelled->getState());
        $this->assertEquals('', $cancelled->getTransitionName());
    }

    public function testARouteFromTheFileIsAddressedByName(): void
    {
        $stateMachine = $this->load('payment.yaml');

        $this->assertEquals(
            'DRAFT -> PAID (PIX)',
            $stateMachine->getTransition(Payment::Draft, Payment::Paid, 'PIX')->describe()
        );

        // ...and the name is matched uppercased, like a state name
        $this->assertEquals(
            'DRAFT -> PAID (CARD)',
            $stateMachine->getTransition(Payment::Draft, Payment::Paid, 'card')->describe()
        );
    }

    public function testAskingWithoutANameIsRejectedForAPairTheFileJoinsTwice(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('There is more than one transition from DRAFT to PAID: PIX, CARD');

        $this->load('payment.yaml')->getTransition(Payment::Draft, Payment::Paid);
    }

    /**
     * The tightened rule, which matters most in a file: an unnamed move alongside a named one
     * between the same two states leaves the pair identifying neither, and it is easy to write by
     * accident when a route is added to an existing entry.
     */
    public function testMixingANamedAndAnUnnamedMoveIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            'DRAFT and PAID are joined more than once, so every move between them must be named'
        );

        $this->load('payment-mixed.yaml');
    }

    /**
     * Names are compared uppercased, so `PIX` and `pix` in the same file are one name declared
     * twice, not two routes.
     */
    public function testTwoRoutesWithTheSameNameAreRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('A transition DRAFT -> PAID (PIX) is already defined');

        $this->load('payment-duplicate-name.yaml');
    }

    /**
     * The file and the equivalent PHP produce the same machine, which is what makes the format a
     * detail rather than a second way of expressing the graph.
     */
    public function testTheFileMatchesTheEquivalentPhpMachine(): void
    {
        $fromYaml = $this->load('payment.yaml');

        $fromPhp = FiniteStateMachine::createMachine(Payment::class, [
            ['DRAFT', 'PAID', \Tests\Fixture\PaidByPix::class, \Tests\Fixture\ConfirmPix::class, 'PIX'],
            ['DRAFT', 'PAID', \Tests\Fixture\PaidByCard::class, \Tests\Fixture\CapturePreAuth::class, 'CARD'],
            ['DRAFT', 'CANCELLED'],
        ]);

        $describe = fn (FiniteStateMachine $m): array => array_map(
            fn (Transition $t): string => $t->describe(),
            $m->possibleTransitions(Payment::Draft)
        );

        $this->assertEquals($describe($fromPhp), $describe($fromYaml));

        // ...and they route identically
        foreach (['PIX', 'CARD', 'CHEQUE'] as $method) {
            $this->assertEquals(
                $fromPhp->autoTransitionFrom(Payment::Draft, ['method' => $method])?->getTransitionName(),
                $fromYaml->autoTransitionFrom(Payment::Draft, ['method' => $method])?->getTransitionName(),
                "the two definitions disagree for method {$method}"
            );
        }
    }
}
