<?php

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;

require __DIR__ . "/vendor/autoload.php";

$stInitial = new State("__VOID__", function ($data) { echo "Void state - " . print_r($data, true); });
$stInStock = new State("IN_STOCK", function ($data) { echo "In stock - " . print_r($data, true); });
$stLastUnits = new State("LAST_UNITS", function ($data) { echo "Last Units - " . print_r($data, true); });
$stOutOfStock = new State("OUT_OF_STOCK", function ($data) { echo "out of Stock - " . print_r($data, true); });
// ----
$stNotRequested = new State("NOT_REQUESTED", function ($data) { echo "Not Requested - " . print_r($data, true); });
$stRequested = new State("REQUESTED_RESUPPLY", function ($data) { echo "Requested Supply - " . print_r($data, true); });
$stResupplied = new State("RESUPPLIED", function ($data) { echo "Ressuoplied - " . print_r($data, true); });
$stUnavailable = new State("UNAVAILABLE", function ($data) { echo "Unavailable - " . print_r($data, true); });

$transitionInStock = Transition::create($stInitial, $stInStock, function ($data) {
    return $data["qty"] >= $data["min_stock"];
});

$transitionLastUnits = Transition::create($stInitial, $stLastUnits, function ($data) {
    return $data["qty"] > 0 && $data["qty"] < $data["min_stock"];
});
$transitionOutOfStock = Transition::create($stInitial, $stOutOfStock, function($data) {
    return $data["qty"] == 0;
});

$stateMachine = FiniteStateMachine::createMachine()
    ->addTransition($transitionInStock)
    ->addTransition($transitionLastUnits)
    ->addTransition($transitionOutOfStock);


echo "\n\nTransitions\n";
$stateMachine->autoTransitionFrom($stInitial, ["qty" => 10, "min_stock" => 20])->process();
$stateMachine->autoTransitionFrom($stInitial, ["qty" => 30, "min_stock" => 20])->process();
$stateMachine->autoTransitionFrom($stInitial, ["qty" => 00, "min_stock" => 20])->process();


$transitionNotRequested = Transition::createMultiple([$stLastUnits, $stOutOfStock], $stNotRequested, function ($data) {
    return !isset($data["invoice_number"]) && !isset($data["status"]);
});

$transitionRequested = Transition::createMultiple([$stLastUnits, $stOutOfStock], $stRequested, function ($data) {
    return isset($data["invoice_number"]) && !isset($data["fulfilment_number"]);
});

$transitionResupplied = Transition::createMultiple([$stLastUnits, $stOutOfStock, $stRequested], $stResupplied, function ($data) {
    return isset($data["fulfilment_number"]);
});

$transitionUnavailable = Transition::createMultiple([$stLastUnits, $stOutOfStock], $stUnavailable, function ($data) {
    return isset($data["status"]);
});


$secondMachine = FiniteStateMachine::createMachine()
    ->addTransitions($transitionNotRequested)
    ->addTransitions($transitionRequested)
    ->addTransitions($transitionResupplied)
    ->addTransitions($transitionUnavailable);

echo "\n\nTransitions 2\n";
var_dump($secondMachine->autoTransitionFrom($stInStock, []));
$secondMachine->autoTransitionFrom($stLastUnits, [])->process();
$secondMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10])->process();
$secondMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10, "fulfilment_number" => 50])->process();
$secondMachine->autoTransitionFrom($stLastUnits, ["status" => "DNB"])->process();

echo "\n\nGet state\n";
var_dump($secondMachine->state('NOT_REQUESTED'));