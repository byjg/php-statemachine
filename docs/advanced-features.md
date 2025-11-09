---
sidebar_position: 4
---

# Advanced Features

## Create Multiple Transitions

You can create multiple transitions from different states to a single state at once:

```php
use ByJG\StateMachine\TransitionConditionInterface;

$condition = new class implements TransitionConditionInterface {
    public function canTransition(?array $data): bool {
        return isset($data["approved"]) && $data["approved"] === true;
    }
};

$transitions = Transition::createMultiple([$from1, $from2], $to, $condition);

$machine = FiniteStateMachine::createMachine()
    ->addTransitions($transitions);
```

This is useful when multiple states can transition to the same destination state under the same conditions.

## Get Possible Transitions

Get all possible transitions from a specific state:

```php
$possibleTransitions = $stateMachine->possibleTransitions($stA);
```

This returns an array of `Transition` objects representing all valid transitions from the given state.

## Get State Object

Retrieve a state object by its name:

```php
// Return null if doesn't exist, otherwise return the State object
$state = $stateMachine->state('OUT_OF_STOCK');
```

:::note
State names are automatically converted to uppercase when stored and retrieved.
:::

## Get Specific Transition

Get a specific transition between two states:

```php
$transition = $stateMachine->getTransition($stA, $stB);
```

Returns the `Transition` object if it exists, or `null` otherwise.
