---
sidebar_position: 6
---

# Using with Laravel

This component requires nothing but PHP, and integrating it needs no bridge package and no
adapter class. Laravel already speaks the three things it takes:

| Laravel                            | plugs into                      | glue |
|------------------------------------|---------------------------------|------|
| the service container              | the resolver — `[app(), 'get']` | none |
| `config('...')` returning an array | `fromDefinition()`              | none |
| an Eloquent enum cast              | the state reference             | none |

The last one is the reason this fits so cleanly. A model with an enum cast hands you
`$order->status` as an enum case, which is exactly what every method of the machine accepts.
The persistence layer and the state machine agree on the type without anyone converting
anything.

This page shows the whole integration by hand. It is four lines, and knowing them is worth more
than any package that hides them. If you would rather not maintain those four lines and the two
traps that come after them, [`byjg/gluo-laravel`](https://github.com/byjg/php-gluo-laravel) ships
them as a connector — see [the end of this page](#doing-it-with-gluo-for-laravel).

## The states

```php
namespace App\Enums;

enum OrderState: string
{
    case Draft     = 'DRAFT';
    case Paid      = 'PAID';
    case Shipped   = 'SHIPPED';
    case Cancelled = 'CANCELLED';
}
```

Cast the column to it, which is native in Laravel 9 and later:

```php
class Order extends Model
{
    protected $casts = [
        'status' => OrderState::class,
    ];
}
```

## The rules

Conditions and actions are ordinary classes, so they are ordinary services. Constructor
injection works as it does anywhere else:

```php
namespace App\Fsm;

class PaymentCleared implements TransitionConditionInterface
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function canTransition(?array $data): bool
    {
        return $this->gateway->isSettled($data['payment_id'] ?? null);
    }
}
```

## The definition

`config/statemachine.php`:

```php
use App\Enums\OrderState;

return [
    'order' => [
        'enum' => OrderState::class,
        'transitions' => [
            ['from' => 'DRAFT', 'to' => 'PAID',      'condition' => \App\Fsm\PaymentCleared::class,
                                                     'action'    => \App\Fsm\SendReceipt::class],
            ['from' => 'PAID',  'to' => 'SHIPPED',   'condition' => \App\Fsm\StockReserved::class],
            ['from' => ['DRAFT', 'PAID'], 'to' => 'CANCELLED'],
        ],
    ],
];
```

`fromDefinition()` takes a plain array, so `config()` feeds it directly — no parser, no file
format this component has to know about.

## Wiring it up

Bind the machine as a singleton so the definition is validated once per process rather than once
per request. The container is the resolver, so a condition with constructor dependencies is
built by Laravel, not by the state machine:

```php
namespace App\Providers;

use ByJG\StateMachine\FiniteStateMachine;
use Illuminate\Support\ServiceProvider;

class StateMachineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('fsm.order', fn ($app) => FiniteStateMachine::fromDefinition(
            config('statemachine.order'),
            [$app, 'get']          // Illuminate\Container\Container implements PSR-11
        ));
    }
}
```

:::tip
Because the machine is built in `register()`, a misspelled state or an unresolvable class name
raises a `TransitionException` when the container first resolves it, not on the one transition
nobody exercised in production. Resolve it in a test to turn that into a build-time check.
:::

## Moving a model

```php
$machine = app('fsm.order');

$next = $machine->autoTransitionFrom($order->status, ['payment_id' => $paymentId]);

if ($next !== null) {
    $order->status = OrderState::from($next->getState());
    $order->save();     // commit the move first
    $next->process();   // then run the side effect
}
```

:::warning
`OrderState::from($next->getState())` works **only because every case value above is uppercase**.
State names are uppercased by the component, so an enum written `case Paid = 'paid'` makes that
line throw a `ValueError` at runtime. Match the case values to the state names, or map the name
back to its case yourself:

```php
$case = collect(OrderState::cases())
    ->first(fn ($case) => strtoupper($case->value) === $next->getState());
```
:::

## Transactions: the one real hazard

The ordering rule is **persist, then process** — if the action ran first and the write failed, the
email or the charge would already be out with no state to match it.

Laravel adds a wrinkle: inside `DB::transaction()`, the write is not durable until the closure
returns. An action that fires a webhook or charges a card from inside the closure escapes even
if the transaction then rolls back.

```php
// WRONG — the receipt is sent even if the transaction rolls back
DB::transaction(function () use ($order, $machine, $data) {
    $next = $machine->autoTransitionFrom($order->status, $data);
    $order->status = OrderState::from($next->getState());
    $order->save();
    $next->process();          // <-- inside the transaction
    $this->somethingElseThatMayThrow();
});

// RIGHT — process after the commit
$next = DB::transaction(function () use ($order, $machine, $data) {
    $next = $machine->autoTransitionFrom($order->status, $data);
    $order->status = OrderState::from($next->getState());
    $order->save();
    $this->somethingElseThatMayThrow();

    return $next;
});

$next?->process();
```

If the side effect is a queued job, `dispatch(...)->afterCommit()` expresses the same thing, and
`ShouldQueue` jobs can opt into it globally with `'after_commit' => true` on the queue connection.

## Validating the definition in CI

Because everything is checked when the machine is built, one test covers the whole definition —
every state name, every class name, every interface:

```php
public function test_the_order_machine_is_well_formed(): void
{
    $this->expectNotToPerformAssertions();

    app('fsm.order');   // throws TransitionException if anything is wrong
}
```

## Doing it with Gluo for Laravel

Everything above is code you own and maintain. Two parts of it are the same in every project and
are easy to get subtly wrong: the transaction ordering, and mapping a state name back to its enum
case. [`byjg/gluo-laravel`](https://github.com/byjg/php-gluo-laravel) ships them as a connector.

```bash
composer require byjg/gluo-laravel byjg/statemachine
```

Machines move into `config/gluo.php` under `gluo.statemachine.machines`, in the same format
`fromDefinition()` reads, and no service provider is written at all:

```php
'statemachine' => [
    'machines' => [
        'order' => [
            'enum' => App\Enums\OrderState::class,
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PAID', 'condition' => App\Fsm\PaymentCleared::class,
                                                    'action'    => App\Fsm\SendReceipt::class],
                ['from' => ['DRAFT', 'PAID'], 'to' => 'CANCELLED'],
            ],
        ],
    ],
],
```

The model declares which machine governs it, and the block under
[Moving a model](#moving-a-model) becomes one call:

```php
use ByJG\Gluo\Laravel\StateMachine\HasStateMachine;
use ByJG\Gluo\Laravel\StateMachine\StatefulModel;

class Order extends Model implements StatefulModel
{
    use HasStateMachine;

    protected $casts = ['status' => OrderState::class];
}
```

```php
DB::transaction(function () use ($order, $data) {
    $order->autoTransition($data);

    $this->somethingElseThatMayThrow();      // rolls back: the receipt never goes out
});
```

| Written by hand | Through the connector |
|---|---|
| One singleton per machine | Machines declared as configuration, built and validated once |
| `OrderState::from($next->getState())` — breaks on lowercase case values | The state is resolved back to its case through the enum the definition names |
| `persist → process`, and `process` moved outside `DB::transaction()` by hand | `Connection::afterCommit()`: runs after the commit, never after a rollback |
| An illegal move requested by a client throws | A `CanTransitionTo` validation rule answers `422` |
| A hand-written test per machine | A published test that checks every declared machine |

The connector wraps none of the component's API: `$order->stateMachine()` hands back the
`FiniteStateMachine` itself, so everything on this page still applies.

**[State machine connector guide →](https://github.com/byjg/php-gluo-laravel/blob/master/docs/state-machine.md)**
