<?php

namespace Tests;

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Article;
use Tests\Fixture\Colliding;
use Tests\Fixture\CollidingPure;
use Tests\Fixture\Foo;
use Tests\Fixture\HasReviewer;
use Tests\Fixture\IntBacked;
use Tests\Fixture\Letter;
use Tests\Fixture\LowerCased;
use Tests\Fixture\PureState;
use Tests\Fixture\Unrelated;

/**
 * The enum is what a machine is: its cases are exactly the states that exist. These cover what
 * that buys — a state cannot be invented by a typo, by a stale definition file, or by a case
 * that belongs to somewhere else.
 */
class EnumBindingTest extends TestCase
{
    protected function articleMachine(): FiniteStateMachine
    {
        return FiniteStateMachine::createMachine(Article::class, [
            [Article::Draft, Article::Review, HasReviewer::class],
            [Article::Review, Article::Published],
        ]);
    }

    /**
     * A case, the string it corresponds to, and a State the machine produced all name the same
     * state. This is what lets an enum query a machine whose graph was defined in YAML.
     */
    public function testACaseAStringAndAStateAllNameTheSameState(): void
    {
        $stateMachine = $this->articleMachine();
        $data = ["reviewer" => "ana"];

        $this->assertTrue($stateMachine->canTransition(Article::Draft, Article::Review, $data));
        $this->assertTrue($stateMachine->canTransition("DRAFT", "REVIEW", $data));
        $this->assertTrue($stateMachine->canTransition(
            $stateMachine->state(Article::Draft),
            $stateMachine->state("REVIEW"),
            $data
        ));

        // ...and mixed, since they are the same thing
        $this->assertTrue($stateMachine->canTransition(Article::Draft, "REVIEW", $data));
    }

    /**
     * The reason the machine has to be bound to one enum: a foreign case satisfies every type
     * declaration a union could express, so only the machine can tell it is wrong. Unrelated
     * even has a Draft case with the same value, which still must not be accepted.
     */
    public function testACaseOfAnotherEnumIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage(
            "Tests\Fixture\Unrelated::Draft is not a state of this machine, which is defined by "
            . "Tests\Fixture\Article"
        );

        $this->articleMachine()->isFinalState(Unrelated::Draft);
    }

    public function testAStringThatIsNotACaseIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'REVIEWED' is not a state of this machine");

        $this->articleMachine()->isFinalState("REVIEWED");
    }

    /**
     * The answer isFinalState() used to give for a state that does not exist was `true`, since
     * nothing leaves a state nobody declared. Being told is the point of the binding.
     */
    public function testAnUnknownStateIsNoLongerReportedAsFinal(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'TYPO' is not a state of this machine");

        $this->articleMachine()->isFinalState("TYPO");
    }

    /**
     * A typo in the declaration itself is caught when the machine is built, which is the one
     * thing a machine without an enum can never do.
     */
    public function testATypoInTheDeclarationIsCaughtWhenTheMachineIsBuilt(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'REVIEEW' is not a state of this machine");

        FiniteStateMachine::createMachine(Article::class, [
            ["DRAFT", "REVIEEW"],
        ]);
    }

    /**
     * Every case is a state, whether or not any transition mentions it. ARCHIVED is declared by
     * the enum and wired to nothing, and both questions have real answers.
     */
    public function testACaseWithNoTransitionsIsStillAState(): void
    {
        $stateMachine = $this->articleMachine();

        $this->assertTrue($stateMachine->isInitialState(Article::Archived));
        $this->assertTrue($stateMachine->isFinalState(Article::Archived));
        $this->assertEquals([], $stateMachine->possibleTransitions(Article::Archived));
        $this->assertEquals("ARCHIVED", $stateMachine->state(Article::Archived)->getState());
    }

    /**
     * State names are compared uppercased, so an enum whose values are not uppercase has to
     * keep matching itself and the strings that correspond to it.
     */
    public function testCaseValuesDoNotHaveToBeUppercase(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(LowerCased::class, [
            [LowerCased::Draft, LowerCased::Review],
        ]);

        $this->assertTrue($stateMachine->canTransition(LowerCased::Draft, LowerCased::Review));
        $this->assertTrue($stateMachine->canTransition("draft", "review"));
        $this->assertTrue($stateMachine->canTransition("DRAFT", "REVIEW"));
        $this->assertEquals("DRAFT", $stateMachine->state(LowerCased::Draft)->getState());
    }

    /**
     * A pure enum has no value, so its cases are named by the case name.
     */
    public function testAPureEnumNamesStatesByTheCaseName(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(PureState::class, [
            [PureState::Draft, PureState::Review],
        ]);

        $this->assertTrue($stateMachine->canTransition(PureState::Draft, PureState::Review));
        $this->assertTrue($stateMachine->canTransition("DRAFT", "REVIEW"));
        $this->assertTrue($stateMachine->isFinalState(PureState::Review));
    }

    /**
     * The whole machine, defined by `enum Foo { case A; case B; }` and nothing else.
     *
     * A pure enum writes each state name once, so there is no value that can drift from the case
     * name. Every entry point has to work from it, including a definition file, where the states
     * arrive as strings and have to match the case names.
     */
    public function testAPureEnumWithNoValuesDefinesAWholeMachine(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Foo::class, [
            [Foo::A, Foo::B],
        ]);

        $this->assertTrue($stateMachine->canTransition(Foo::A, Foo::B));
        $this->assertFalse($stateMachine->canTransition(Foo::B, Foo::A));

        $this->assertTrue($stateMachine->isInitialState(Foo::A));
        $this->assertTrue($stateMachine->isFinalState(Foo::B));

        $this->assertCount(1, $stateMachine->possibleTransitions(Foo::A));
        $this->assertCount(0, $stateMachine->possibleTransitions(Foo::B));

        $this->assertEquals("A", $stateMachine->state(Foo::A)->getState());
        $this->assertEquals("A", $stateMachine->state("a")->getState());

        $next = $stateMachine->autoTransitionFrom(Foo::A, ["k" => "v"]);
        $this->assertEquals("B", $next->getState());
        $this->assertEquals("A", $next->getPreviousState()->getState());
        $this->assertEquals(["k" => "v"], $next->getData());

        // ...and the same machine written as a definition, where the states are strings
        $fromDefinition = FiniteStateMachine::fromDefinition([
            "enum" => Foo::class,
            "transitions" => [
                ["from" => "A", "to" => "B"],
            ],
        ]);

        $this->assertTrue($fromDefinition->canTransition(Foo::A, Foo::B));
        $this->assertNotNull($fromDefinition->getTransition("A", "B"));
    }

    /**
     * PHP accepts `case A` and `case a` as two distinct cases, so a pure enum can collide the
     * same way a backed one can.
     */
    public function testAPureEnumWhoseCaseNamesCollideIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("has two cases naming the state 'A'");

        FiniteStateMachine::createMachine(CollidingPure::class);
    }

    public function testAnIntBackedEnumIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("Tests\Fixture\IntBacked is backed by int");

        FiniteStateMachine::createMachine(IntBacked::class);
    }

    /**
     * Two cases that differ only in case would name one state, and nothing could tell which of
     * them a transition meant.
     */
    public function testAnEnumWhoseCasesCollideIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("has two cases naming the state 'DRAFT'");

        FiniteStateMachine::createMachine(Colliding::class);
    }

    public function testSomethingThatIsNotAnEnumIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'Tests\Fixture\HasReviewer' is not an enum");

        FiniteStateMachine::createMachine(HasReviewer::class);
    }

    public function testAClassThatDoesNotExistIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'Tests\Fixture\Artcle' is not an enum");

        FiniteStateMachine::createMachine("Tests\Fixture\Artcle");
    }

    /**
     * Two machines over two enums do not leak into one another.
     */
    public function testTwoMachinesOverTwoEnumsStaySeparate(): void
    {
        $articles = $this->articleMachine();
        $letters = FiniteStateMachine::createMachine(Letter::class, [[Letter::A, Letter::B]]);

        $this->assertTrue($articles->canTransition(Article::Draft, Article::Review, ["reviewer" => "ana"]));
        $this->assertTrue($letters->canTransition(Letter::A, Letter::B));

        $this->expectException(TransitionException::class);
        $letters->canTransition(Article::Draft, Article::Review);
    }

    /**
     * A state produced by the machine goes back in as-is, which is what makes continuing from
     * where you got to read naturally.
     */
    public function testAStateProducedByTheMachineCanBeFedBackIn(): void
    {
        $stateMachine = $this->articleMachine();

        $review = $stateMachine->autoTransitionFrom(Article::Draft, ["reviewer" => "ana"]);
        $this->assertEquals("REVIEW", $review->getState());

        $published = $stateMachine->autoTransitionFrom($review, []);
        $this->assertEquals("PUBLISHED", $published->getState());
        $this->assertEquals("REVIEW", $published->getPreviousState()->getState());
    }
}
