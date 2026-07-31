---
sidebar_position: 6
---

# Using with Laravel

This component requires nothing but PHP, and integrating it needs no bridge package and no
adapter class. Laravel already speaks the three things it takes:

| Laravel | plugs into | glue |
|---|---|---|
| the service container | the resolver — `[app(), 'get']` | none |
| `config('...')` returning an array | `fromDefinition()` | none |
| an Eloquent enum cast | the state reference | none |

The last one is the reason this fits so cleanly. A model with an enum cast hands you
`$order->status` as an enum case, which is exactly what every method of the machine accepts.
The persistence layer and the state machine agree on the type without anyone converting
anything.

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

`$order->status` goes in as an enum case and `$next->getState()` comes out as the string the case
corresponds to, which is what `OrderState::from()` wants.

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

## What is deliberately not here

There is no `byjg/statemachine-laravel` package, because there is nothing for it to do that the
four lines above do not. If you want `$order->transitionTo(OrderState::Paid, $data)` as a trait on
your models, write it in your application — it is a wrapper around the block under
[Moving a model](#moving-a-model), and it belongs where your models are.
