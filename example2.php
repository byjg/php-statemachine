<?php

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionConditionInterface;

require __DIR__ . "/vendor/autoload.php";

/**
 * The rules named by example2.yaml. Each one is a class, so each one can be unit tested,
 * type checked and debugged — which is why the definition names classes instead of carrying
 * expressions such as "qty >= min_stock" as strings.
 */
class InStock implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return $data["qty"] >= $data["min_stock"];
    }
}

class LastUnits implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return $data["qty"] > 0 && $data["qty"] < $data["min_stock"];
    }
}

class OutOfStock implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return $data["qty"] == 0;
    }
}

class NothingRequested implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return !isset($data["invoice_number"]) && !isset($data["status"]);
    }
}

class ResupplyRequested implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return isset($data["invoice_number"]) && !isset($data["fulfilment_number"]);
    }
}

class Fulfilled implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return isset($data["fulfilment_number"]);
    }
}

class Discontinued implements TransitionConditionInterface
{
    #[\Override]
    public function canTransition(?array $data): bool
    {
        return isset($data["status"]);
    }
}

/**
 * One action, named by every transition in the file.
 *
 * Receiving both ends of the move is what makes a single object reusable like this: the
 * announcement knows where it came from without a separate class per destination. The machine
 * builds it once and shares that instance across all of them.
 */
class Announce implements TransitionActionInterface
{
    #[\Override]
    public function execute(State $from, State $to, ?array $data): void
    {
        echo "  {$from} -> {$to} " . json_encode($data) . "\n";
    }
}

$definition = Serialize::fromYaml((string)file_get_contents(__DIR__ . "/example2.yaml"))->toArray();

$stockMachine = FiniteStateMachine::fromDefinition($definition["stock"]);
$resupplyMachine = FiniteStateMachine::fromDefinition($definition["resupply"]);

$stVoid = $stockMachine->state("__VOID__");
$stLastUnits = $resupplyMachine->state("LAST_UNITS");

// IN_STOCK is a state of the first machine, not of the second one. Asking the resupply machine
// for it would return null, so it is built directly to show what happens when a machine is
// handed a state it does not know: nothing is reachable.
$stInStock = new State("IN_STOCK");

echo "Where the product sits\n";
foreach ([["qty" => 10, "min_stock" => 20], ["qty" => 30, "min_stock" => 20], ["qty" => 0, "min_stock" => 20]] as $data) {
    // The machine decides; the caller decides when it happened. In real code the new state
    // would be persisted here, before process() runs the side effect.
    $stockMachine->autoTransitionFrom($stVoid, $data)?->process();
}

echo "\nWhat is being done about it\n";
// IN_STOCK has no outgoing transition in this machine, so there is nowhere to go.
var_dump($resupplyMachine->autoTransitionFrom($stInStock, []));

$resupplyMachine->autoTransitionFrom($stLastUnits, [])?->process();
$resupplyMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10])?->process();
$resupplyMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10, "fulfilment_number" => 50])?->process();
$resupplyMachine->autoTransitionFrom($stLastUnits, ["status" => "DNB"])?->process();

echo "\nGet state\n";
var_dump($resupplyMachine->state("NOT_REQUESTED"));
