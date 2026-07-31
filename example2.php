<?php

use ByJG\Serializer\Serialize;
use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\TransitionActionInterface;
use ByJG\StateMachine\TransitionConditionInterface;
use ByJG\StateMachine\TransitionException;

require __DIR__ . "/vendor/autoload.php";

/**
 * Every state either machine can be in. Two machines share this enum: the first decides where a
 * product sits, the second what is being done about it, and both are the same lifecycle.
 */
enum Product: string
{
    case Start = '__VOID__';
    case InStock = 'IN_STOCK';
    case LastUnits = 'LAST_UNITS';
    case OutOfStock = 'OUT_OF_STOCK';
    case NotRequested = 'NOT_REQUESTED';
    case RequestedResupply = 'REQUESTED_RESUPPLY';
    case Resupplied = 'RESUPPLIED';
    case Unavailable = 'UNAVAILABLE';
}

/**
 * A perfectly good enum that names something else entirely.
 *
 * A case of it satisfies every type declaration the machine could write down, so nothing but
 * the machine — which knows Product is its enum — can tell that it is the wrong one.
 */
enum Warehouse: string
{
    case Aisle = 'AISLE';
}

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

echo "Where the product sits\n";
foreach ([["qty" => 10, "min_stock" => 20], ["qty" => 30, "min_stock" => 20], ["qty" => 0, "min_stock" => 20]] as $data) {
    // The machine decides; the caller decides when it happened. In real code the new state
    // would be persisted here, before process() runs the side effect.
    $stockMachine->autoTransitionFrom(Product::Start, $data)?->process();
}

echo "\nWhat is being done about it\n";
// IN_STOCK is a state of the resupply machine, but nothing leaves it: there is nowhere to go.
var_dump($resupplyMachine->autoTransitionFrom(Product::InStock, []));

$resupplyMachine->autoTransitionFrom(Product::LastUnits, [])?->process();
$resupplyMachine->autoTransitionFrom(Product::LastUnits, ["invoice_number" => 10])?->process();
$resupplyMachine->autoTransitionFrom(Product::LastUnits, ["invoice_number" => 10, "fulfilment_number" => 50])?->process();
$resupplyMachine->autoTransitionFrom(Product::LastUnits, ["status" => "DNB"])?->process();

echo "\nA state the enum declares but this machine never leaves\n";
var_dump($resupplyMachine->isFinalState(Product::Resupplied));

echo "\nA state that does not belong to this machine at all\n";
try {
    $resupplyMachine->isFinalState(Warehouse::Aisle);
} catch (TransitionException $e) {
    echo "  " . $e->getMessage() . "\n";
}
