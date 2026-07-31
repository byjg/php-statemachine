<?php

namespace Tests;

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionConditionInterface;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\Article;
use Tests\Fixture\HasReviewer;
use Tests\Fixture\NotACollaborator;
use Tests\Fixture\RecordingAction;
use Tests\Fixture\Stock;
use Tests\Fixture\ThresholdCondition;

/**
 * Covers the declarative side of the machine: a definition that is data rather than code,
 * where conditions and actions are named instead of instantiated by the caller.
 */
class DefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingAction::reset();
    }

    /**
     * The definition format did not change: an instance in slot 2 or 3 still goes straight
     * through, so machines written against the old signature keep working.
     */
    public function testAnInstanceStillPassesThroughUntouched(): void
    {
        $condition = new HasReviewer();
        $action = new RecordingAction();

        $stateMachine = FiniteStateMachine::createMachine(Article::class, [
            [Article::Draft, Article::Review, $condition, $action],
        ]);

        $transition = $stateMachine->getTransition(Article::Draft, Article::Review);
        $this->assertSame($action, $transition->getTransitionAction());

        $this->assertFalse($stateMachine->canTransition(Article::Draft, Article::Review, []));
        $this->assertTrue($stateMachine->canTransition(Article::Draft, Article::Review, ["reviewer" => "ana"]));
    }

    /**
     * The whole point of A: a class name is enough, and the machine builds the collaborator.
     */
    public function testACollaboratorNamedByClassIsBuiltAndWiredUp(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Article::class, [
            [Article::Draft, Article::Review, HasReviewer::class, RecordingAction::class],
        ]);

        // The condition really governs the move
        $this->assertNull($stateMachine->autoTransitionFrom(Article::Draft, []));

        $next = $stateMachine->autoTransitionFrom(Article::Draft, ["reviewer" => "ana"]);
        $this->assertEquals("REVIEW", $next->getState());

        // ...and so does the action, still only when the caller asks for it
        $this->assertEquals([], RecordingAction::$log);
        $next->process();
        $this->assertEquals(["DRAFT->REVIEW"], RecordingAction::$log);
    }

    public function testTheSameClassNamedTwiceYieldsOneSharedInstance(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Article::class, [
            [Article::Draft, Article::Review, null, RecordingAction::class],
            [Article::Review, Article::Published, null, RecordingAction::class],
        ]);

        $first = $stateMachine->getTransition(Article::Draft, Article::Review);
        $second = $stateMachine->getTransition(Article::Review, Article::Published);

        $this->assertSame($first->getTransitionAction(), $second->getTransitionAction());
    }

    /**
     * ThresholdCondition needs a constructor argument, so `new $className()` cannot produce it.
     * This is the case the resolver exists for.
     */
    public function testTheResolverSuppliesWhatTheMachineCannotBuildItself(): void
    {
        $container = [ThresholdCondition::class => new ThresholdCondition(20)];

        $stateMachine = FiniteStateMachine::createMachine(
            Stock::class,
            [[Stock::Depleted, Stock::InStock, ThresholdCondition::class]],
            fn (string $name): object => $container[$name]
        );

        $from = Stock::Depleted;
        $to = Stock::InStock;

        $this->assertTrue($stateMachine->canTransition($from, $to, ["qty" => 25]));
        $this->assertFalse($stateMachine->canTransition($from, $to, ["qty" => 5]));
    }

    /**
     * A typo fails while the machine is being built, not on the one transition nobody
     * exercised in production.
     */
    public function testAnUnknownClassIsRejectedWhenTheMachineIsBuilt(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("The class 'Tests\Fixture\HasReviwer' declared as");

        FiniteStateMachine::createMachine(Article::class, [
            ["DRAFT", "REVIEW", "Tests\Fixture\HasReviwer"],
        ]);
    }

    public function testAConditionUsedAsAnActionIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must implement " . TransitionActionInterface::class);

        FiniteStateMachine::createMachine(Article::class, [
            ["DRAFT", "REVIEW", null, HasReviewer::class],
        ]);
    }

    public function testAClassImplementingNeitherInterfaceIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must implement " . TransitionConditionInterface::class);

        FiniteStateMachine::createMachine(Article::class, [
            ["DRAFT", "REVIEW", NotACollaborator::class],
        ]);
    }

    /**
     * State names are matched by their uppercased form, which matters once the definition is
     * hand-written in a file rather than produced by PHP variables.
     */
    public function testStateNamesAreMatchedRegardlessOfCase(): void
    {
        $stateMachine = FiniteStateMachine::createMachine(Article::class, [
            ["draft", "Review"],
            ["DRAFT", "published"],
        ]);

        $this->assertCount(2, $stateMachine->possibleTransitions("DRAFT"));
        $this->assertTrue($stateMachine->isInitialState("draft"));
        $this->assertNotNull($stateMachine->getTransition("DRAFT", "REVIEW"));
    }

    public function testBuildsAMachineFromADefinitionArray(): void
    {
        $stateMachine = FiniteStateMachine::fromDefinition([
            "enum" => Article::class,
            "transitions" => [
                ["from" => "DRAFT", "to" => "REVIEW", "condition" => HasReviewer::class],
                ["from" => "REVIEW", "to" => "PUBLISHED", "action" => RecordingAction::class],
            ],
        ]);

        $this->assertTrue($stateMachine->isInitialState(Article::Draft));
        $this->assertTrue($stateMachine->isFinalState(Article::Published));

        $published = $stateMachine->transition(Article::Review, Article::Published);
        $published->process();
        $this->assertEquals(["REVIEW->PUBLISHED"], RecordingAction::$log);
    }

    /**
     * A list in `from` is the declarative Transition::createMultiple(): one entry, one
     * transition per origin, all sharing the condition and the action.
     */
    public function testAListOfOriginStatesFansOutIntoOneTransitionEach(): void
    {
        $stateMachine = FiniteStateMachine::fromDefinition([
            "enum" => Stock::class,
            "transitions" => [
                ["from" => ["LAST_UNITS", "OUT_OF_STOCK"], "to" => "RESUPPLIED", "action" => RecordingAction::class],
            ],
        ]);

        $fromLastUnits = $stateMachine->getTransition(Stock::LastUnits, Stock::Resupplied);
        $fromOutOfStock = $stateMachine->getTransition(Stock::OutOfStock, Stock::Resupplied);

        $this->assertNotNull($fromLastUnits);
        $this->assertNotNull($fromOutOfStock);
        $this->assertSame($fromLastUnits->getTransitionAction(), $fromOutOfStock->getTransitionAction());
    }

    /**
     * Without the enum the file could not say which states exist, so every name in it would be
     * taken on trust — which is exactly what the binding is there to stop.
     */
    public function testADefinitionWithoutAnEnumIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must declare the 'enum' naming its states");

        FiniteStateMachine::fromDefinition([
            "transitions" => [
                ["from" => "DRAFT", "to" => "REVIEW"],
            ],
        ]);
    }

    /**
     * A state name in the file that the enum does not declare — a stale entry left behind by a
     * rename, typically — is rejected when the file is read.
     */
    public function testADefinitionNamingAStateTheEnumDoesNotDeclareIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("'RETIRED' is not a state of this machine");

        FiniteStateMachine::fromDefinition([
            "enum" => Article::class,
            "transitions" => [
                ["from" => "PUBLISHED", "to" => "RETIRED"],
            ],
        ]);
    }

    public function testADefinitionWithoutATransitionsListIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must declare a 'transitions' list");

        FiniteStateMachine::fromDefinition(["enum" => Article::class, "states" => ["DRAFT"]]);
    }

    public function testATransitionMissingAnEndIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("The transition #1 must declare both 'from' and 'to'");

        FiniteStateMachine::fromDefinition([
            "enum" => Article::class,
            "transitions" => [
                ["from" => "DRAFT", "to" => "REVIEW"],
                ["from" => "REVIEW"],
            ],
        ]);
    }

    /**
     * B end to end: the definition is written in YAML, parsed by byjg/serializer, and the
     * machine never learns which format it came from.
     */
    public function testLoadsAYamlDefinitionThroughTheSerializer(): void
    {
        $definition = Serialize::fromYaml((string)file_get_contents(__DIR__ . "/fixtures/machine.yaml"))->toArray();

        $stateMachine = FiniteStateMachine::fromDefinition($definition);

        // The graph declared in the file
        $this->assertTrue($stateMachine->isInitialState(Article::Draft));
        $this->assertTrue($stateMachine->isFinalState(Article::Archived));
        $this->assertCount(1, $stateMachine->possibleTransitions(Article::Draft));
        $this->assertCount(2, $stateMachine->possibleTransitions(Article::Review));

        // The condition named in the file governs the move
        $this->assertNull($stateMachine->autoTransitionFrom(Article::Draft, ["reviewer" => ""]));

        $review = $stateMachine->autoTransitionFrom(Article::Draft, ["reviewer" => "ana"]);
        $this->assertEquals("REVIEW", $review->getState());
        $this->assertEquals("DRAFT", $review->getPreviousState()->getState());

        // ...and so does the action
        $review->process();
        $this->assertEquals(["DRAFT->REVIEW"], RecordingAction::$log);
    }

    /**
     * A YAML definition and the equivalent hand-written PHP produce the same machine, which
     * is what makes the format a detail rather than a second way of expressing the graph.
     */
    public function testTheYamlDefinitionMatchesTheEquivalentPhpMachine(): void
    {
        $fromYaml = FiniteStateMachine::fromDefinition(
            Serialize::fromYaml((string)file_get_contents(__DIR__ . "/fixtures/machine.yaml"))->toArray()
        );

        $fromPhp = FiniteStateMachine::createMachine(Article::class, [
            [Article::Draft, Article::Review, new HasReviewer(), new RecordingAction()],
            [Article::Review, Article::Published, null, new RecordingAction()],
            [Article::Review, Article::Archived],
            [Article::Published, Article::Archived],
        ]);

        foreach (Article::cases() as $from) {
            foreach (Article::cases() as $to) {
                $this->assertEquals(
                    !is_null($fromPhp->getTransition($from, $to)),
                    !is_null($fromYaml->getTransition($from, $to)),
                    "{$from->value} -> {$to->value} differs between the YAML and the PHP definition"
                );
            }
        }
    }
}
