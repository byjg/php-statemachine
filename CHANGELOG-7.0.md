# Changelog 7.0

Version 7.0 makes two changes: side effects move from the **state** to the **transition**, and a
machine is now defined by an **enum**.

In 6.x a state carried a `StateActionInterface` that ran when the state was processed. That
model cannot express behaviour that depends on *how* a state was reached: if `C` must do one
thing when reached from `A` and another when reached from `B`, the only options were an `if`
inside the action or splitting `C` into `C_VIA_A` and `C_VIA_B`.

Actions now live on the transition, which knows both ends of the move. A state-level action is
just the special case of the same action attached to every inbound transition, so nothing is
lost and the state-splitting workaround goes away.

The second change follows from asking what a `State` was for. Everything the machine did with
one on the way *in* reduced to its name, so `new State('A')` handed over an object whose data
and origin could not mean anything — and the machine had no way to tell a real state from a
typo. `isFinalState('TYPOO')` answered `true`, perfectly logically: nothing leaves a state
nobody declared. A machine is now bound to an enum whose cases are exactly its states, so a
state cannot be invented by a typo, by a stale name in a definition file, or by a case that
belongs to some other enum. `State` becomes what the machine hands back, not what you build.

## Breaking Changes

| | 6.x | 7.0 |
|---|---|---|
| **State constructor** | `new State($name, StateActionInterface $action)` | not called by you — a machine's states are the cases of its enum |
| **Naming a state** | a `State` object | an enum case, the string it corresponds to, or a `State` the machine produced |
| **Creating a machine** | `createMachine($transitions)` | `createMachine(OrderState::class, $transitions)` |
| **An unknown state** | answered as if it existed | `TransitionException` |
| **`state()`** | `?State` — `null` when unknown | `State` — throws when the reference names no state |
| **Action interface** | `StateActionInterface::execute(?array $data): void` | `TransitionActionInterface::execute(State $from, State $to, ?array $data): void` |
| **Where actions are declared** | on the `State` | 4th argument of `Transition`, `Transition::create()` and `Transition::createMultiple()` |
| **`$state->process()`** | ran the state's own action | runs the action of the transition the state was reached through |
| **`State::arrivedThrough()`** | — | takes the `Transition`, not `(State, ?action)` |
| **Standalone processing** | `$fsm->state('C')->process()` ran C's action | no-op — that state was not reached by a transition |

### Removed

- `StateActionInterface` — replaced by `TransitionActionInterface`.
- The second argument of the `State` constructor.
- The idea of a state that the machine does not know about.

### Added

- `TransitionActionInterface` — the side effect of taking one specific transition.
- `FiniteStateMachine::transition($from, $to, ?array $data = null): ?State` —
  performs an explicit move and returns the state reached, stamped with the transition it came
  through. Returns `null` when the move is not allowed, or throws under
  `throwErrorIfCannotTransition()`. This is the counterpart of `autoTransitionFrom()` for when
  you already know both ends, and the only safe way to obtain a processable state on that path.
- `State::arrivedThrough(Transition $transition): void` — records the transition a state was
  reached through. Called by the state machine.
- `State::getPreviousState(): ?State` — the state this one was reached from, or `null`.
- `State::nameOf($ref): string` — the name a reference refers to. Internal; the machine and
  `Transition` use it to accept a case, a string or a `State` interchangeably.
- **Named transitions.** Two states may be joined by more than one move when the moves are named —
  `DRAFT` to `PAID` by PIX, by card and by transfer are three transitions with three conditions and
  three actions. `Transition::named()`, a 5th argument on `Transition::__construct()`/`create()`/
  `createMultiple()`, a `name` key in a definition, and a 4th argument on `getTransition()`,
  `canTransition()` and `transition()` to pick a route. `State::getTransitionName()` reports which
  route was taken, so it can be persisted next to the state. Two *unnamed* moves between the same
  pair remain a declaration error.
- A 4th optional argument for the transition action on `Transition::__construct()`,
  `Transition::create()` and `Transition::createMultiple()`.
- A 4th slot in the `createMachine()` array form: `[$from, $to, $condition, $action]`.
- The condition and the action slots also accept the **name of a class** implementing the
  matching interface, resolved when the machine is built. A second optional argument of
  `createMachine()` takes a resolver — any `callable(string): object`, so `[$container, 'get']`
  works — for collaborators that need constructor arguments.
- `FiniteStateMachine::fromDefinition(array $definition, ?callable $resolver = null)` — builds a
  machine from an `['enum' => ..., 'transitions' => [['name' => ..., 'from' => ..., 'to' => ..., 'condition' => ..., 'action' => ...]]]`
  array, where `from` may be a list to declare the same move out of several states and `name`
  distinguishes several moves joining the same pair. The
  definition is a plain array, so YAML/JSON parsing stays outside this package and it keeps
  requiring nothing but PHP. See [Declarative Definition](docs/declarative-definition.md).

## Defining a machine by an enum

Declare the states as a string-backed enum and give it to the machine:

```php
enum OrderState: string
{
    case Draft     = 'DRAFT';
    case Review    = 'REVIEW';
    case Published = 'PUBLISHED';
}

$machine = FiniteStateMachine::createMachine(OrderState::class, [
    [OrderState::Draft, OrderState::Review, HasReviewer::class],
]);
```

Every method that names a state accepts three interchangeable forms:

```php
$machine->canTransition(OrderState::Draft, OrderState::Review, $data);   // a case
$machine->canTransition('DRAFT', 'REVIEW', $data);                       // the string it corresponds to
$machine->autoTransitionFrom($next, $moreData);                          // a State the machine produced
```

Names are compared uppercased, so `'draft'` and `'DRAFT'` are the same state, and an enum whose
values are not uppercase still matches itself. A pure enum is named by its case names instead of
its values. An int-backed enum is rejected — it would name states `"1"` and `"2"`.

### What this catches

```php
$machine->isFinalState('REVIEWD');          // TransitionException — was `true`
$machine->isFinalState(OtherEnum::Draft);   // TransitionException — was accepted
FiniteStateMachine::createMachine(OrderState::class, [['DRAFT', 'REVIEEW']]);   // throws at build
```

The last one is the one a machine without an enum can never catch: a typo in the declaration
used to create a second state that nothing could reach.

These throw whether or not `throwErrorIfCannotTransition()` is enabled. That flag governs how the
machine reports a move it *disallows*; a state that does not exist is not a move it disallowed,
it is a mistake in the caller.

### In a definition file

A definition names its enum, which is what lets the file be hand-written safely — every `from`
and `to` in it must be one of the cases:

```yaml
enum: 'App\Fsm\OrderState'
transitions:
  - from: DRAFT
    to: REVIEW
```

### The boundary this draws

The states of a machine must be known when the code is compiled. A workflow whose stages each
tenant invents for themselves, or whose state names are rows in a table, cannot be expressed with
this component — PHP enums are compile-time constructs and there is no runtime way to create one.

This is deliberate. A state machine whose states are not predictable can guarantee very little:
every name is taken on trust, every typo becomes a new state, and no answer about the graph can
be trusted. Choosing predictability is what makes the rest of the guarantees possible.

## Migration

Every example below is written against this enum, which is the first thing to add when
migrating: the states you already have, declared once.

```php
enum Letter: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
}
```

### Building the machine

The machine is bound to the enum, and states are named by its cases rather than by `State`
objects you construct:

```php
// 6.x
$stA = new State('A');
$stB = new State('B');

$machine = FiniteStateMachine::createMachine()
    ->addTransition(new Transition($stA, $stB));

$machine->canTransition($stA, $stB);
$machine->isFinalState($stB);

// 7.0
$machine = FiniteStateMachine::createMachine(Letter::class)
    ->addTransition(new Transition(Letter::A, Letter::B));

$machine->canTransition(Letter::A, Letter::B);
$machine->isFinalState(Letter::B);
```

`Transition`, `Transition::create()` and `Transition::createMultiple()` take the same forms, so
`new State()` disappears from calling code entirely.

The string a case corresponds to works everywhere a case does — `'A'`, `'a'` and `Letter::A` are
one state — which is what lets a machine defined in a file be queried with the enum. Prefer the
case in code you write by hand: it cannot be misspelled.

### A state action that was the same on every route in

Attach the action to every inbound transition. `createMultiple()` does this in one call:

```php
// 6.x
$stC = new State('C', $arrivalAction);
$machine->addTransition(new Transition($stA, $stC));
$machine->addTransition(new Transition($stB, $stC));

// 7.0
$machine = FiniteStateMachine::createMachine(Letter::class)
    ->addTransitions(
        Transition::createMultiple([Letter::A, Letter::B], Letter::C, null, $arrivalAction)
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
$machine = FiniteStateMachine::createMachine(Letter::class)
    ->addTransition(Transition::create(Letter::A, Letter::C, null, $viaAAction))
    ->addTransition(Transition::create(Letter::B, Letter::C, null, $viaBAction));
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

`$fsm->state(Letter::C)->process()` is now a no-op, because that state was not reached by
anything. Obtain the state from the move itself:

```php
// 6.x
$fsm->state('C')->process();

// 7.0
$next = $fsm->transition(Letter::A, Letter::C, $data);   // or autoTransitionFrom()
$next?->process();
```

### Code that asked about a state the machine does not have

There was no way to be told before, so this is the one place a working 6.x call can become an
exception rather than a different answer:

```php
// 6.x — answered, wrongly
$machine->isFinalState(new State('TYPOO'));   // true: nothing leaves a state nobody declared
$machine->state('TYPOO');                     // null

// 7.0 — TransitionException in both cases
```

If you were relying on `state()` returning `null` as an existence check, the check is no longer
needed: every case of the enum is a state of the machine, and anything else is a mistake.

## Unchanged

- `TransitionConditionInterface` and its `canTransition(?array $data): bool` signature.
- What `canTransition()`, `autoTransitionFrom()`, `possibleTransitions()`, `getTransition()`,
  `isInitialState()` and `isFinalState()` mean. They accept a wider set of arguments and reject
  a state that does not exist, but the questions they answer are the same.
- The state machine still never runs actions on its own: it decides whether a move is legal,
  and the caller decides when it happened by calling `process()`. Persist the new state before
  processing it, so a failed write cannot leave a side effect already dispatched.

## Requirements

- PHP 8.3, 8.4, 8.5 and 8.6 are now supported: `"php": ">=8.3 <8.7"`.
  The previous `<8.6` upper bound excluded PHP 8.6, since `<8.6` is exclusive.

### ByJG dependencies

- `byjg/serializer` is now `^7.0`.

While 7.0 is unreleased these resolve to `7.0.x-dev` from each component's
`7.0` branch, via `minimum-stability: dev` with `prefer-stable: true`.

## Toolchain

- PHPUnit updated to `^12.5`.
- Psalm moved out of `require-dev` into its own manifest, `tools/psalm/composer.json`.

  Psalm enumerates the PHP versions it supports and no published release lists
  8.6. As a dev dependency it made `composer install` fail on the 8.6 build job
  before any test ran. It now installs separately, only for the Psalm job.

  `composer psalm` still works — it bootstraps the tool and runs it.

- PHPUnit 13 is deliberately **not** used. It requires PHP `>=8.4.1`, breaking the
  8.3 floor, and needs `sebastian/diff ^9.0`, which stable Psalm 6.16.1 rejects —
  a combination that silently resolves Psalm to an unreleased `6.x-dev` branch.

## Continuous Integration

- The build matrix now includes PHP 8.6.
- The Psalm job runs on PHP 8.5 and installs Psalm from `tools/psalm`.

## Housekeeping

- `phpunit.xml.dist` renamed to `phpunit.xml`.
