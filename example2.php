<?php

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;

require __DIR__ . "/vendor/autoload.php";

$stInitial = new State("__VOID__");
$stInStock = new State("IN_STOCK");
$stLastUnits = new State("LAST_UNITS");
$stOutOfStock = new State("OUT_OF_STOCK");
// ----
$stNotRequested = new State("NOT_REQUESTED");
$stRequested = new State("REQUESTED_RESUPPLY");
$stResupplied = new State("RESUPPLIED");
$stUnavailable = new State("UNAVAILABLE");

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
var_dump($stateMachine->autoTransitionFrom($stInitial, ["qty" => 10, "min_stock" => 20]));
var_dump($stateMachine->autoTransitionFrom($stInitial, ["qty" => 30, "min_stock" => 20]));
var_dump($stateMachine->autoTransitionFrom($stInitial, ["qty" => 00, "min_stock" => 20]));


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
var_dump($secondMachine->autoTransitionFrom($stLastUnits, []));
var_dump($secondMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10]));
var_dump($secondMachine->autoTransitionFrom($stLastUnits, ["invoice_number" => 10, "fulfilment_number" => 50]));
var_dump($secondMachine->autoTransitionFrom($stLastUnits, ["status" => "DNB"]));

echo "\n\nGet state\n";
var_dump($secondMachine->stateFactory('NOT_REQUESTED'));