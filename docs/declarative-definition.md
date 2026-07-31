---
sidebar_position: 5
---

# Declarative Definition

The examples so far build the machine in PHP: you instantiate the conditions and the actions,
and hand the objects over. The machine can also be described as **data** — a definition where
conditions and actions are named by their class rather than instantiated by the caller.

This is what makes it possible to keep the whole graph in a YAML file, in a database column, or
anywhere else outside the code.

## Naming a class instead of passing an instance

Any place that accepts a `TransitionConditionInterface` or a `TransitionActionInterface` in the
`createMachine()` array also accepts the name of a class implementing it:

```php
$stateMachine = FiniteStateMachine::createMachine([
    ['DRAFT',  'REVIEW',    HasReviewer::class, NotifyReviewer::class],
    ['REVIEW', 'PUBLISHED', Approved::class],
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
    [['EMPTY', 'IN_STOCK', MinimumStock::class]],
    fn (string $name) => $container->get($name)
);
```

The resolver is a plain callable, so a PSR-11 container works directly:

```php
$stateMachine = FiniteStateMachine::createMachine($definition, [$container, 'get']);
```

## Loading the definition from a file

`fromDefinition()` takes the graph as an associative array:

```php
$stateMachine = FiniteStateMachine::fromDefinition([
    'transitions' => [
        ['from' => 'DRAFT',  'to' => 'REVIEW',    'condition' => HasReviewer::class],
        ['from' => 'REVIEW', 'to' => 'PUBLISHED', 'action'    => Publish::class],
    ],
]);
```

Only `from` and `to` are required; `condition` and `action` are optional.

Because the definition is a plain array, the format it was written in is not this component's
concern — and this component therefore requires no parser. Parse the file with whatever you
already use and hand over the result. With [byjg/serializer](https://github.com/byjg/php-serializer):

```yaml
# machine.yaml
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
```

`Serialize::fromJson()` works the same way, as does any other parser that produces an array.

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

## What belongs in the definition

Keep the **graph** in the definition and the **logic** in classes. The definition says which
moves exist and which class decides each one; it is not an expression language, and there is
deliberately no way to write `condition: "qty >= min_stock"` in it. A condition expressed as a
class can be unit tested, type checked and debugged; the same rule written as a string in a
configuration file can be none of those things.
