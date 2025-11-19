---
sidebar_position: 3
---

# Error Handling

By default, the state machine returns `false` or `null` when a transition is not possible. However, you can configure it to throw exceptions instead.

## Enabling Exception Throwing

Use the `throwErrorIfCannotTransition()` method to enable exception throwing:

```php
$stateMachine = FiniteStateMachine::createMachine()
    ->addTransition($transitionA_B)
    ->addTransition($transitionA_C)
    ->throwErrorIfCannotTransition();
```

## Exception Behavior

### With canTransition()

When exceptions are enabled, `canTransition()` will throw a `TransitionException` if the transition is not possible:

```php
try {
    $stateMachine->canTransition($stA, $stD);
} catch (TransitionException $e) {
    echo $e->getMessage(); // "Cannot transition from A to D"
}
```

### With autoTransitionFrom()

When exceptions are enabled, `autoTransitionFrom()` will throw a `TransitionException` if no valid transition is found:

```php
try {
    $stateMachine->autoTransitionFrom($stInitial, ["invalid" => "data"]);
} catch (TransitionException $e) {
    echo $e->getMessage(); // "There is not possible transitions from __VOID__ with the data provided"
}
```

## Exception Class

The `TransitionException` class extends the standard PHP `Exception` class:

```php
use ByJG\StateMachine\TransitionException;
```
