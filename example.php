<?php

use ByJG\StateMachine\FiniteStateMachine;
use ByJG\StateMachine\State;
use ByJG\StateMachine\Transition;

require "vendor/autoload.php";

$stA = new State("A");
$stB = new State("B");
$stC = new State("C");
$stD = new State("D");

$transitionA_B = new Transition($stA, $stB);
$transitionA_C = new Transition($stA, $stC);
$transitionB_D = new Transition($stB, $stD, function($data) {
    return !is_null($data);
});

$stateMachine = FiniteStateMachine::createMachine()
//    ->throwErrorIfCannotTransition()
    ->addTransition($transitionA_B)
    ->addTransition($transitionA_C)
    ->addTransition($transitionB_D);

echo "Transitions\n";
var_dump($stateMachine->canTransition($stA, $stB));
var_dump($stateMachine->canTransition($stA, $stC));
var_dump($stateMachine->canTransition($stA, $stD));
var_dump($stateMachine->canTransition($stB, $stA));
var_dump($stateMachine->canTransition($stB, $stD));
var_dump($stateMachine->canTransition($stB, $stD, ["some_info"]));
var_dump($stateMachine->canTransition($stC, $stD));

echo "Next States\n";
var_dump($stateMachine->getNextStates($stA));
var_dump($stateMachine->getNextStates($stB));
var_dump($stateMachine->getNextStates($stC));
var_dump($stateMachine->getNextStates($stD));

echo "Previous States\n";
var_dump($stateMachine->getPreviousStates($stA));
var_dump($stateMachine->getPreviousStates($stB));
var_dump($stateMachine->getPreviousStates($stC));
var_dump($stateMachine->getPreviousStates($stD));
