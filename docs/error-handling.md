---
sidebar_position: 3
---

# Error Handling

By default, the state machine returns `false` or `null` when a transition is not possible. However, you can configure it to throw exceptions instead.

## Enabling Exception Throwing

Use the `throwErrorIfCannotTransition()` method to enable exception throwing:

```php
$stateMachine = FiniteStateMachine::createMachine(Letter::class)
    ->addTransition($transitionA_B)
    ->addTransition($transitionA_C)
    ->throwErrorIfCannotTransition();
```

## Exception Behavior

### With canTransition()

When exceptions are enabled, `canTransition()` will throw a `TransitionException` if the transition is not possible:

```php
try {
    $stateMachine->canTransition(Letter::A, Letter::D);
} catch (TransitionException $e) {
    echo $e->getMessage(); // "Cannot transition from A to D"
}
```

### With transition()

`transition()` returns `null` when the move is not declared or its condition denies it. With
exceptions enabled it throws instead:

```php
try {
    $stateMachine->transition(Letter::B, Letter::A);
} catch (TransitionException $e) {
    echo $e->getMessage(); // "Cannot transition from B to A"
}
```

### With autoTransitionFrom()

When exceptions are enabled, `autoTransitionFrom()` will throw a `TransitionException` if no valid transition is found:

```php
try {
    $stateMachine->autoTransitionFrom(Stock::Start, ["invalid" => "data"]);
} catch (TransitionException $e) {
    echo $e->getMessage(); // "There is not possible transitions from __VOID__ with the data provided"
}
```

## Ambiguous Transitions

`throwErrorIfCannotTransition()` covers the case where *no* transition matches. The opposite
case — data matching *several* transitions — is not an error by default: `autoTransitionFrom()`
takes the first transition declared that matches.

Enable `throwErrorIfAmbiguousTransition()` when your conditions are meant to be mutually
exclusive and you would rather hear about an overlap than depend on declaration order:

```php
$stateMachine = FiniteStateMachine::createMachine(Stock::class)
    ->addTransition($transitionRequested)
    ->addTransition($transitionUnavailable)
    ->throwErrorIfAmbiguousTransition();

try {
    $stateMachine->autoTransitionFrom(Stock::LastUnits, ["invoice_number" => 10, "status" => "DNB"]);
} catch (TransitionException $e) {
    // "Ambiguous transition from LAST_UNITS: the data provided matches REQUESTED_RESUPPLY, UNAVAILABLE"
    echo $e->getMessage();
}
```

The two options are independent and can be combined. See
[Auto Transition](auto-transition.md) for the evaluation order this protects you from.

## Exception Class

The `TransitionException` class extends the standard PHP `Exception` class:

```php
use ByJG\StateMachine\TransitionException;
```

## A State That Does Not Exist

The errors above are *answers*: the machine is telling you a move it knows about is not allowed.
Naming a state the machine does not have is a different thing — a mistake in the caller — and it
always throws, whether or not `throwErrorIfCannotTransition()` is enabled:

```php
$stateMachine->isFinalState('REVIEWD');        // TransitionException: not a state of this machine
$stateMachine->isFinalState(OtherEnum::Draft); // TransitionException: defined by Stock
```

This is what binding the machine to an enum buys. Without it, `isFinalState('REVIEWD')` would
answer `true` — perfectly logically, since nothing leaves a state nobody declared.
