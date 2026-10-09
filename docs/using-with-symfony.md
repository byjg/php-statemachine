---
sidebar_position: 7
---

# Using with Symfony

As with Laravel, no bridge package and no adapter class are needed. Symfony already speaks the
three things this component takes:

| Symfony | plugs into | glue |
|---|---|---|
| a `ServiceLocator` (PSR-11) | the resolver — `[$locator, 'get']` | none |
| `Yaml::parseFile()` | `fromDefinition()` | none |
| a Doctrine `enumType` column | the state reference | none |

:::note
Symfony has its own [Workflow component](https://symfony.com/doc/current/workflow.html), and it is
the bigger tool: Petri-net markings, a marking store that writes state back onto your entity,
seven lifecycle events, expression-language guards, and `workflow:dump` for diagrams. If you want
that, use it.

The differences worth deciding on are narrower than "framework dependency" — `symfony/workflow`
requires nothing but PHP either. They are:

- **Guards there are event listeners.** A `StateMachine` built without an `EventDispatcher` has no
  guards at all: `can()` answers `true` for any declared move. Conditions and actions here are
  first-class with no dispatcher involved.
- **Places there are strings; states here are enum cases.** A transition naming a place that was
  never declared is accepted by `symfony/workflow` — the typo silently becomes a new place. Here it
  is a `TransitionException` when the machine is built.
- **The destination is chosen differently.** There you name the transition and a guard approves it.
  Here `autoTransitionFrom()` can pick the destination from the data.
:::

## The states

```php
namespace App\Enum;

enum OrderState: string
{
    case Draft     = 'DRAFT';
    case Paid      = 'PAID';
    case Shipped   = 'SHIPPED';
    case Cancelled = 'CANCELLED';
}
```

Doctrine maps it natively with `enumType` (ORM 2.11 and later), so the entity property is already
an enum case — which is what the machine accepts:

```php
#[ORM\Entity]
class Order
{
    #[ORM\Column(type: 'string', enumType: OrderState::class)]
    private OrderState $status = OrderState::Draft;

    public function getStatus(): OrderState
    {
        return $this->status;
    }
}
```

## The rules

Conditions and actions are ordinary classes, so autowiring handles them:

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

`config/state_machine/order.yaml`:

```yaml
enum: 'App\Enum\OrderState'

transitions:
  - from: DRAFT
    to: PAID
    condition: 'App\Fsm\PaymentCleared'
    action: 'App\Fsm\SendReceipt'

  - from: PAID
    to: SHIPPED
    condition: 'App\Fsm\StockReserved'

  - from: [DRAFT, PAID]
    to: CANCELLED
```

Keep it out of `config/packages/`, since it is not a bundle configuration — it is data your own
factory reads.

## Wiring it up

Symfony ships `symfony/yaml`, so parse the file with it. `fromDefinition()` takes a plain array
and does not care where it came from:

```php
namespace App\Fsm;

use ByJG\StateMachine\FiniteStateMachine;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Yaml\Yaml;
use Psr\Container\ContainerInterface;

class OrderMachineFactory
{
    public function __construct(
        #[AutowireLocator(TransitionConditionInterface::class)]
        private ContainerInterface $rules,
        private string $projectDir,
    ) {
    }

    public function create(): FiniteStateMachine
    {
        return FiniteStateMachine::fromDefinition(
            Yaml::parseFile($this->projectDir . '/config/state_machine/order.yaml'),
            [$this->rules, 'get']
        );
    }
}
```

:::warning
Do **not** pass the main container as the resolver. Symfony services are private by default, so
`$container->get(PaymentCleared::class)` throws `ServiceNotFoundException` even though the service
exists. A `ServiceLocator` is the supported way to fetch services by id at runtime, and
`#[AutowireLocator]` builds one for you from a tag or an interface.
:::

For the locator to contain your rules, tag them — autoconfiguration does it by interface:

```yaml
# config/services.yaml
services:
    _instanceof:
        ByJG\StateMachine\TransitionConditionInterface:
            tags: ['app.fsm.rule']
        ByJG\StateMachine\TransitionActionInterface:
            tags: ['app.fsm.rule']
```

then locate on the tag instead:

```php
#[AutowireLocator('app.fsm.rule')]
private ContainerInterface $rules,
```

Register the machine itself as a service so the definition is validated once per process:

```yaml
services:
    App\Fsm\OrderMachineFactory:
        arguments:
            $projectDir: '%kernel.project_dir%'

    ByJG\StateMachine\FiniteStateMachine:
        factory: ['@App\Fsm\OrderMachineFactory', 'create']
```

## Moving an entity

```php
$next = $this->machine->autoTransitionFrom($order->getStatus(), ['payment_id' => $paymentId]);

if ($next !== null) {
    $order->setStatus(OrderState::from($next->getState()));
    $this->entityManager->flush();   // commit the move first
    $next->process();                // then run the side effect
}
```

## Transactions: the one real hazard

The ordering rule is **persist, then process** — if the action ran first and the write failed, the
email or the charge would already be out with no state to match it.

Doctrine adds a wrinkle: inside `wrapInTransaction()`, the write is not durable until the closure
returns, so a side effect fired from inside escapes even if the transaction then rolls back.

```php
// WRONG — the receipt is sent even if the transaction rolls back
$em->wrapInTransaction(function () use ($order, $data) {
    $next = $this->machine->autoTransitionFrom($order->getStatus(), $data);
    $order->setStatus(OrderState::from($next->getState()));
    $next->process();                  // <-- inside the transaction
    $this->somethingElseThatMayThrow();
});

// RIGHT — process after the commit
$next = $em->wrapInTransaction(function () use ($order, $data) {
    $next = $this->machine->autoTransitionFrom($order->getStatus(), $data);
    $order->setStatus(OrderState::from($next->getState()));
    $this->somethingElseThatMayThrow();

    return $next;
});

$next?->process();
```

If the side effect is a message, dispatching it from the action and letting
[`DoctrineTransactionMiddleware`](https://symfony.com/doc/current/messenger.html#middleware)
handle the boundary expresses the same rule.

## Validating the definition at container-compile time

Because everything is checked when the machine is built, warming up the container is enough to
prove the whole definition — every state name, every class name, every interface. A smoke test:

```php
public function testTheOrderMachineIsWellFormed(): void
{
    $this->expectNotToPerformAssertions();

    self::getContainer()->get(FiniteStateMachine::class);   // throws if anything is wrong
}
```
