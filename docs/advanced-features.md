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

$transitions = Transition::createMultiple(
    [OrderState::Draft, OrderState::Rejected],
    OrderState::Review,
    $condition
);

$machine = FiniteStateMachine::createMachine(OrderState::class)
    ->addTransitions($transitions);
```

This is useful when multiple states can transition to the same destination state under the same conditions.

## Get Possible Transitions

Get all possible transitions from a specific state:

```php
$possibleTransitions = $stateMachine->possibleTransitions(OrderState::Draft);
```

This returns an array of `Transition` objects representing all valid transitions from the given state.

## Get State Object

Retrieve the `State` object a reference names:

```php
$state = $stateMachine->state(OrderState::OutOfStock);
$state = $stateMachine->state('OUT_OF_STOCK');   // the same state
```

This cannot fail: every case of the enum is a state of the machine, and a reference that names
no case raises a `TransitionException`. There is no "does this state exist" question left to ask.

:::note
State names are compared uppercased, so `'out_of_stock'` and `'OUT_OF_STOCK'` name the same
state — and so does an enum whose case values are not uppercase.
:::

## Get Specific Transition

Get a specific transition between two states:

```php
$transition = $stateMachine->getTransition(OrderState::Draft, OrderState::Review);
```

Returns the `Transition` object if it exists, or `null` otherwise.
