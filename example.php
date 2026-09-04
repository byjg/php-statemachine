<?php

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionConditionInterface;

require __DIR__ . "/vendor/autoload.php";

/**
 * The states of the machine. The enum is what makes them predictable: nothing outside these
 * four cases can name a state, so a typo in the YAML is rejected when the file is read rather
 * than silently becoming a state nothing can reach.
 */
enum Letters: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
}

/**
 * The graph lives in example.yaml and the rules live here, in a class the definition names.
 *
 * Conditions must be free of side effects: the machine may evaluate several of them to decide
 * where to go, and evaluating one must not change anything.
 */
class RequiresData implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return !is_null($data);
    }
}

// The definition is a plain array, so the state machine never learns it came from YAML.
// A second argument accepts a resolver — see docs/declarative-definition.md — for conditions
// that need constructor arguments. This one does not, so the machine builds it itself.
// The enum comes from the file, so nothing here has to repeat it.
$stateMachine = FiniteStateMachine::fromDefinition(
    Serialize::fromYaml((string)file_get_contents(__DIR__ . "/example.yaml"))->toArray()
);

$stA = Letters::A;
$stB = Letters::B;
$stC = Letters::C;
$stD = Letters::D;

$show = function (string $label, bool $value): void {
    echo str_pad($label, 34) . ($value ? "true" : "false") . "\n";
};

echo "Initial and Final States\n";
foreach (Letters::cases() as $state) {
    $show("  {$state->value} is initial", $stateMachine->isInitialState($state));
}
foreach (Letters::cases() as $state) {
    $show("  {$state->value} is final", $stateMachine->isFinalState($state));
}

echo "\nTransitions\n";
$show("  A -> B", $stateMachine->canTransition($stA, $stB));
$show("  A -> C", $stateMachine->canTransition($stA, $stC));
$show("  A -> D", $stateMachine->canTransition($stA, $stD));
$show("  B -> A", $stateMachine->canTransition($stB, $stA));
$show("  B -> D without data", $stateMachine->canTransition($stB, $stD));
$show("  B -> D with data", $stateMachine->canTransition($stB, $stD, ["some_info"]));
$show("  C -> D", $stateMachine->canTransition($stC, $stD));

echo "\nPossible transitions\n";
foreach (Letters::cases() as $state) {
    $reachable = array_map(
        fn ($transition): string => (string)$transition->getDesiredState(),
        $stateMachine->possibleTransitions($state)
    );

    echo "  from {$state->value}: " . (empty($reachable) ? "(none)" : implode(", ", $reachable)) . "\n";
}

echo "\nPerforming the move\n";
$next = $stateMachine->transition(Letters::B, Letters::D, ["some_info"]);
echo "  B -> D returned: " . ($next instanceof State ? "{$next}" : "null") . "\n";
echo "  reached from:    {$next->getPreviousState()}\n";

// Persist the new state before processing it, so a failed write cannot leave a side effect
// already dispatched. This transition declares no action, so process() does nothing.
$next->process();
