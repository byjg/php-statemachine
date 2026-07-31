---
sidebar_position: 5
---

# Declarative Definition

The examples so far build the machine in PHP: you instantiate the conditions and the actions,
and hand the objects over. The machine can also be described as **data** — a definition where
conditions and actions are named by their class rather than instantiated by the caller.

This is what makes it possible to keep the whole graph in a YAML file, in a database column, or
anywhere else outside the code.

## Naming the states

Every machine is defined by an enum. Its cases are the states, and the definition file names
that enum so the two cannot drift apart:

```php
enum ArticleState: string
{
    case Draft     = 'DRAFT';
    case Review    = 'REVIEW';
    case Published = 'PUBLISHED';
    case Archived  = 'ARCHIVED';
}
```

The strings in the file are the case values, so the same machine can be *defined* in YAML and
*queried* with the enum. Case does not matter — `draft`, `Draft` and `DRAFT` name the same state
— but a name that is not a case at all does not exist.

:::warning
The states of a machine must therefore be known when the code is compiled. A workflow whose
stages each tenant invents for themselves, or whose state names are rows in a table, cannot be
expressed with this component: PHP enums are compile-time constructs, and there is no runtime
way to create one. This is a deliberate boundary — a state machine whose states are not
predictable can guarantee very little — not an oversight.
:::

## Naming a class instead of passing an instance

Any place that accepts a `TransitionConditionInterface` or a `TransitionActionInterface` in the
`createMachine()` array also accepts the name of a class implementing it:

```php
$stateMachine = FiniteStateMachine::createMachine(ArticleState::class, [
    [ArticleState::Draft,  ArticleState::Review,    HasReviewer::class, NotifyReviewer::class],
    [ArticleState::Review, ArticleState::Published, Approved::class],
]);
```

The machine builds each class once and shares that instance with every transition naming it.
That is safe because both interfaces receive everything they operate on as arguments, and
conditions are required to be free of side effects.

Resolution happens **while the machine is being built**, not while it runs. A class that does
not exist, or one that does not implement the interface expected in that slot, raises a
`TransitionException` at construction — not on the one transition nobody exercised in
production.

## Supplying collaborators the machine cannot build

`new $className()` only works for classes with no constructor argument. When a condition needs
one, pass a resolver as the second argument of `createMachine()`:

```php
$stateMachine = FiniteStateMachine::createMachine(
    StockState::class,
    [[StockState::Depleted, StockState::InStock, MinimumStock::class]],
    fn (string $name) => $container->get($name)
);
```

The resolver is a plain callable, so a PSR-11 container works directly:

```php
$stateMachine = FiniteStateMachine::createMachine(StockState::class, $definition, [$container, 'get']);
```

## Loading the definition from a file

`fromDefinition()` takes the graph as an associative array:

```php
$stateMachine = FiniteStateMachine::fromDefinition([
    'enum' => ArticleState::class,
    'transitions' => [
        ['from' => 'DRAFT',  'to' => 'REVIEW',    'condition' => HasReviewer::class],
        ['from' => 'REVIEW', 'to' => 'PUBLISHED', 'action'    => Publish::class],
    ],
]);
```

`enum` is required, and it is what makes a definition file safe to hand-write: every `from` and
`to` must be one of its cases, so a name left behind by a rename is rejected when the file is
read instead of silently becoming a state nothing can reach.

Beyond that, only `from` and `to` are required; `condition` and `action` are optional.

Because the definition is a plain array, the format it was written in is not this component's
concern — and this component therefore requires no parser. Parse the file with whatever you
already use and hand over the result. With [byjg/serializer](https://github.com/byjg/php-serializer):

```yaml
# machine.yaml
enum: 'App\Fsm\ArticleState'

transitions:
  - from: DRAFT
    to: REVIEW
    condition: 'App\Fsm\HasReviewer'
    action: 'App\Fsm\NotifyReviewer'

  - from: REVIEW
    to: PUBLISHED
    action: 'App\Fsm\Publish'

  - from: [REVIEW, PUBLISHED]
    to: ARCHIVED
```

```php
use ByJG\Serializer\Serialize;

$stateMachine = FiniteStateMachine::fromDefinition(
    Serialize::fromYaml(file_get_contents('machine.yaml'))->toArray(),
    [$container, 'get']            // optional
);

// ...and the enum can then query the machine the file defined
$stateMachine->autoTransitionFrom(ArticleState::Draft, $data);
```

`Serialize::fromJson()` works the same way, as does any other parser that produces an array.

### The complete format

Five keys, two of them required:

```yaml
enum: 'App\Fsm\OrderState'          # REQUIRED — its cases are the states of the machine

transitions:                         # REQUIRED — a list, evaluated in the order written
  - from: DRAFT                      # REQUIRED — a case value, or a list of them
    to: PAID                         # REQUIRED — a case value
    name: PIX                        # optional — tells apart several moves joining one pair
    condition: 'App\Fsm\PaidByPix'   # optional — a TransitionConditionInterface class
    action: 'App\Fsm\ConfirmPix'     # optional — a TransitionActionInterface class
```

| key | required | value |
|---|---|---|
| `enum` | yes | class name of the enum whose cases are the states |
| `transitions` | yes | list of entries, each one a move |
| `transitions[].from` | yes | a case value, or a list of them to declare the move out of several states |
| `transitions[].to` | yes | a case value |
| `transitions[].name` | no | required *only* when the same pair of states is joined more than once |
| `transitions[].condition` | no | class implementing `TransitionConditionInterface`; the move is always allowed without one |
| `transitions[].action` | no | class implementing `TransitionActionInterface`; run by `process()`, never by the machine |

Quote the class names. `condition: App\Fsm\PaidByPix` unquoted happens to work, because YAML
treats a backslash literally in a plain scalar, but single quotes say so on purpose.

Everything in the file is checked when it is read: `enum` must be an enum, every `from` and `to`
must be one of its cases, and every `condition` and `action` must exist and implement the right
interface.

### Naming the routes into a state

When one pair of states is joined by more than one move — paid by PIX, by card, by transfer —
each move is named, and each carries its own condition and its own action:

```yaml
transitions:
  - name: PIX
    from: DRAFT
    to: PAID
    condition: 'App\Fsm\PaidByPix'
    action: 'App\Fsm\ConfirmPix'

  - name: CARD
    from: DRAFT
    to: PAID
    condition: 'App\Fsm\PaidByCard'
    action: 'App\Fsm\CapturePreAuth'

  - from: DRAFT          # unnamed: this pair is joined once, so the pair says which move it is
    to: CANCELLED
```

`autoTransitionFrom()` needs nothing extra — it evaluates the conditions of every move leaving the
state, and their sharing a destination changes nothing:

```php
$paid = $machine->autoTransitionFrom(OrderState::Draft, ['method' => 'CARD']);

$paid->getState();            // 'PAID'
$paid->getTransitionName();   // 'CARD'  <- which route got you here, worth persisting
$paid->process();             // runs CapturePreAuth, and only that
```

Two rules the file is held to, both reported when it is read:

- **Two moves joining one pair must both be named.** An unnamed move next to a named one leaves
  the pair identifying neither, so it is rejected rather than left to fail later at lookup.
- **Names are compared uppercased**, like state names. `PIX` and `pix` in the same file are one
  name declared twice, not two routes.

### Several origin states at once

A list in `from` declares the same move out of every state in it. It is the declarative form of
`Transition::createMultiple()`, and it is how a side effect that must happen on every way into a
state is declared once:

```yaml
  - from: [LAST_UNITS, OUT_OF_STOCK]
    to: RESUPPLIED
    condition: 'App\Fsm\WasFulfilled'
    action: 'App\Fsm\NotifyPurchasing'
```

A `name` on such an entry is shared by every transition it produces, which is legal because they
start from different states.

## What belongs in the definition

Keep the **graph** in the definition and the **logic** in classes. The definition says which
moves exist and which class decides each one; it is not an expression language, and there is
deliberately no way to write `condition: "qty >= min_stock"` in it. A condition expressed as a
class can be unit tested, type checked and debugged; the same rule written as a string in a
configuration file can be none of those things.
