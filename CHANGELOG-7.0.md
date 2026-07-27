# Changelog 7.0

Version 7.0 moves side effects from the **state** to the **transition**.

In 6.x a state carried a `StateActionInterface` that ran when the state was processed. That
model cannot express behaviour that depends on *how* a state was reached: if `C` must do one
thing when reached from `A` and another when reached from `B`, the only options were an `if`
inside the action or splitting `C` into `C_VIA_A` and `C_VIA_B`.

Actions now live on the transition, which knows both ends of the move. A state-level action is
just the special case of the same action attached to every inbound transition, so nothing is
lost and the state-splitting workaround goes away.

## Breaking Changes

| | 6.x | 7.0 |
|---|---|---|
| **State constructor** | `new State($name, StateActionInterface $action)` | `new State($name)` — actions are declared on transitions |
| **Action interface** | `StateActionInterface::execute(?array $data): void` | `TransitionActionInterface::execute(State $from, State $to, ?array $data): void` |
| **Where actions are declared** | on the `State` | 4th argument of `Transition`, `Transition::create()` and `Transition::createMultiple()` |
| **`$state->process()`** | ran the state's own action | runs the action of the transition the state was reached through |
| **Standalone processing** | `$fsm->state('C')->process()` ran C's action | no-op — that state was not reached by a transition |

### Removed

- `StateActionInterface` — replaced by `TransitionActionInterface`.
- The second argument of the `State` constructor.

### Added

- `TransitionActionInterface` — the side effect of taking one specific transition.
- `FiniteStateMachine::transition(State $from, State $to, ?array $data = null): ?State` —
  performs an explicit move and returns the state reached, stamped with the transition it came
  through. Returns `null` when the move is not allowed, or throws under
  `throwErrorIfCannotTransition()`. This is the counterpart of `autoTransitionFrom()` for when
  you already know both ends, and the only safe way to obtain a processable state on that path.
- `State::arrivedThrough(State $from, ?TransitionActionInterface $action): void` — records the
  transition a state was reached through. Called by the state machine.
- `State::getPreviousState(): ?State` — the state this one was reached from, or `null`.
- A 4th optional argument for the transition action on `Transition::__construct()`,
  `Transition::create()` and `Transition::createMultiple()`.
- A 4th slot in the `createMachine()` array form: `['A', 'B', $condition, $action]`.

## Migration

### A state action that was the same on every route in

Attach the action to every inbound transition. `createMultiple()` does this in one call:

```php
// 6.x
$stC = new State('C', $arrivalAction);
$machine->addTransition(new Transition($stA, $stC));
$machine->addTransition(new Transition($stB, $stC));

// 7.0
$stC = new State('C');
$machine->addTransitions(
    Transition::createMultiple([$stA, $stB], $stC, null, $arrivalAction)
);
```

### A state action that branched on where it came from

This is what 7.0 exists for — the branch disappears:

```php
// 6.x
$stC = new State('C', new class implements StateActionInterface {
    public function execute(?array $data): void {
        if ($data['from'] === 'A') { /* ... */ } else { /* ... */ }
    }
});

// 7.0
$machine->addTransition(Transition::create($stA, $stC, null, $viaAAction));
$machine->addTransition(Transition::create($stB, $stC, null, $viaBAction));
```

### The action signature

`execute()` now receives both ends of the transition. Ignore them if you do not need them:

```php
// 6.x
public function execute(?array $data): void

// 7.0
public function execute(State $from, State $to, ?array $data): void
```

`$to` is the state that was reached, so `$to->getData()` returns the same array as `$data`.
Receiving `$from` and `$to` makes one action object reusable across many transitions, which is
how cross-cutting concerns such as audit logging are expressed:

```php
$audit = new class implements TransitionActionInterface {
    public function execute(State $from, State $to, ?array $data): void {
        $this->logger->info("moved {$from} -> {$to}");
    }
};
```

### Code that processed a state without transitioning

`$fsm->state('C')->process()` is now a no-op, because that state was not reached by anything.
Obtain the state from the move itself:

```php
// 7.0
$next = $fsm->transition($current, $stC, $data);   // or autoTransitionFrom()
$next?->process();
```

## Unchanged

- `TransitionConditionInterface` and its `canTransition(?array $data): bool` signature.
- `canTransition()`, `autoTransitionFrom()`, `possibleTransitions()`, `getTransition()`,
  `isInitialState()`, `isFinalState()`, `state()`.
- The state machine still never runs actions on its own: it decides whether a move is legal,
  and the caller decides when it happened by calling `process()`. Persist the new state before
  processing it, so a failed write cannot leave a side effect already dispatched.
