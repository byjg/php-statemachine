<?php

namespace Tests;

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionConditionInterface;
use ByJG\StateMachine\TransitionException;
use PHPUnit\Framework\TestCase;
use Tests\Fixture\HasReviewer;
use Tests\Fixture\NotACollaborator;
use Tests\Fixture\RecordingAction;
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

        $stateMachine = FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", $condition, $action],
        ]);

        $transition = $stateMachine->getTransition($stateMachine->state("DRAFT"), $stateMachine->state("REVIEW"));
        $this->assertSame($action, $transition->getTransitionAction());

        $this->assertFalse($stateMachine->canTransition($stateMachine->state("DRAFT"), $stateMachine->state("REVIEW"), []));
        $this->assertTrue($stateMachine->canTransition($stateMachine->state("DRAFT"), $stateMachine->state("REVIEW"), ["reviewer" => "ana"]));
    }

    /**
     * The whole point of A: a class name is enough, and the machine builds the collaborator.
     */
    public function testACollaboratorNamedByClassIsBuiltAndWiredUp(): void
    {
        $stateMachine = FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", HasReviewer::class, RecordingAction::class],
        ]);

        // The condition really governs the move
        $this->assertNull($stateMachine->autoTransitionFrom($stateMachine->state("DRAFT"), []));

        $next = $stateMachine->autoTransitionFrom($stateMachine->state("DRAFT"), ["reviewer" => "ana"]);
        $this->assertEquals("REVIEW", $next->getState());

        // ...and so does the action, still only when the caller asks for it
        $this->assertEquals([], RecordingAction::$log);
        $next->process();
        $this->assertEquals(["DRAFT->REVIEW"], RecordingAction::$log);
    }

    public function testTheSameClassNamedTwiceYieldsOneSharedInstance(): void
    {
        $stateMachine = FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", null, RecordingAction::class],
            ["REVIEW", "PUBLISHED", null, RecordingAction::class],
        ]);

        $first = $stateMachine->getTransition($stateMachine->state("DRAFT"), $stateMachine->state("REVIEW"));
        $second = $stateMachine->getTransition($stateMachine->state("REVIEW"), $stateMachine->state("PUBLISHED"));

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
            [["EMPTY", "IN_STOCK", ThresholdCondition::class]],
            fn (string $name): object => $container[$name]
        );

        $from = $stateMachine->state("EMPTY");
        $to = $stateMachine->state("IN_STOCK");

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

        FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", "Tests\Fixture\HasReviwer"],
        ]);
    }

    public function testAConditionUsedAsAnActionIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must implement " . TransitionActionInterface::class);

        FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", null, HasReviewer::class],
        ]);
    }

    public function testAClassImplementingNeitherInterfaceIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must implement " . TransitionConditionInterface::class);

        FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", NotACollaborator::class],
        ]);
    }

    /**
     * State names are matched by their uppercased form, which matters once the definition is
     * hand-written in a file rather than produced by PHP variables.
     */
    public function testStateNamesAreMatchedRegardlessOfCase(): void
    {
        $stateMachine = FiniteStateMachine::createMachine([
            ["draft", "Review"],
            ["DRAFT", "published"],
        ]);

        $this->assertCount(2, $stateMachine->possibleTransitions($stateMachine->state("DRAFT")));
        $this->assertTrue($stateMachine->isInitialState($stateMachine->state("draft")));
        $this->assertNotNull($stateMachine->getTransition($stateMachine->state("DRAFT"), $stateMachine->state("REVIEW")));
    }

    public function testBuildsAMachineFromADefinitionArray(): void
    {
        $stateMachine = FiniteStateMachine::fromDefinition([
            "transitions" => [
                ["from" => "DRAFT", "to" => "REVIEW", "condition" => HasReviewer::class],
                ["from" => "REVIEW", "to" => "PUBLISHED", "action" => RecordingAction::class],
            ],
        ]);

        $this->assertTrue($stateMachine->isInitialState($stateMachine->state("DRAFT")));
        $this->assertTrue($stateMachine->isFinalState($stateMachine->state("PUBLISHED")));

        $published = $stateMachine->transition($stateMachine->state("REVIEW"), $stateMachine->state("PUBLISHED"));
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
            "transitions" => [
                ["from" => ["LAST_UNITS", "OUT_OF_STOCK"], "to" => "RESUPPLIED", "action" => RecordingAction::class],
            ],
        ]);

        $fromLastUnits = $stateMachine->getTransition($stateMachine->state("LAST_UNITS"), $stateMachine->state("RESUPPLIED"));
        $fromOutOfStock = $stateMachine->getTransition($stateMachine->state("OUT_OF_STOCK"), $stateMachine->state("RESUPPLIED"));

        $this->assertNotNull($fromLastUnits);
        $this->assertNotNull($fromOutOfStock);
        $this->assertSame($fromLastUnits->getTransitionAction(), $fromOutOfStock->getTransitionAction());
    }

    public function testADefinitionWithoutATransitionsListIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("must declare a 'transitions' list");

        FiniteStateMachine::fromDefinition(["states" => ["DRAFT"]]);
    }

    public function testATransitionMissingAnEndIsRejected(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage("The transition #1 must declare both 'from' and 'to'");

        FiniteStateMachine::fromDefinition([
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
        $this->assertTrue($stateMachine->isInitialState($stateMachine->state("DRAFT")));
        $this->assertTrue($stateMachine->isFinalState($stateMachine->state("ARCHIVED")));
        $this->assertCount(1, $stateMachine->possibleTransitions($stateMachine->state("DRAFT")));
        $this->assertCount(2, $stateMachine->possibleTransitions($stateMachine->state("REVIEW")));

        // The condition named in the file governs the move
        $this->assertNull($stateMachine->autoTransitionFrom($stateMachine->state("DRAFT"), ["reviewer" => ""]));

        $review = $stateMachine->autoTransitionFrom($stateMachine->state("DRAFT"), ["reviewer" => "ana"]);
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

        $stDraft = new State("DRAFT");
        $stReview = new State("REVIEW");
        $stPublished = new State("PUBLISHED");
        $stArchived = new State("ARCHIVED");

        $fromPhp = FiniteStateMachine::createMachine([
            ["DRAFT", "REVIEW", new HasReviewer(), new RecordingAction()],
            ["REVIEW", "PUBLISHED", null, new RecordingAction()],
            ["REVIEW", "ARCHIVED"],
            ["PUBLISHED", "ARCHIVED"],
        ]);

        foreach ([$stDraft, $stReview, $stPublished, $stArchived] as $from) {
            foreach ([$stDraft, $stReview, $stPublished, $stArchived] as $to) {
                $this->assertEquals(
                    !is_null($fromPhp->getTransition($from, $to)),
                    !is_null($fromYaml->getTransition($from, $to)),
                    "{$from} -> {$to} differs between the YAML and the PHP definition"
                );
            }
        }
    }
}
