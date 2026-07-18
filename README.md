# Laravel Action

[![Latest Version on Packagist](https://img.shields.io/packagist/v/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/action)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/iaK/action/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/iaK/action/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/iaK/action/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/iaK/action/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/iak/action.svg?style=flat-square)](https://packagist.org/packages/iak/action)

A dont-get-in-my-way action pattern wrapper for laravel applications.

For a guide on how I use actions, which is also the way this package is tailored towards, see this blogpost: https://berglind.dev/blog/all-about-the-action/

## Why do i need this package?

The action pattern is awesome because it's dead simple. What this package aims to do, is to provide some helpers and save you from writing the boring boilerplate code, and focus on your business logic.

Stuff like idempotency, concurrency control, database transactions, circuit breaking, retires and debugging is ready to use without it cluttering your code, easy for both humans and agents to reach for.

Lets say you have a piece of code like this:

```php
class ChargeOrderAction
{
    public function handle(Order $order): Payment
    {
        $payment = app(PaymentGateway::class)->charge($order->customer, $order->total);

        $order->update(['status' => OrderStatus::Paid]);
        $order->payments()->create([
            'amount' => $order->total,
            'reference' => $payment->reference,
        ]);

        return $payment;
    }
}
```

In reality, you probably won't get away with a solution as simple as this. You will probably need transactions, maybe idempotency, and want to retry it on deadlocks or failures.

Instead of cluttering our beatiful and simple action with that logic, we can instead chain it on when we call it.

```php
$payment = ChargeOrderAction::make()
    ->idempotent("order:{$order->id}:charge", ttl: now()->addDay())
    ->retry(times: 3, backoff: [100, 1000])
    ->transactional(attempts: 2)
    ->handle($order);
```

This is just as big a win for agents as it is for you. Instead of generating fifty lines of locking, caching and retry boilerplate — which you then get to review for subtle bugs — an agent chains a few calls that are hard to get wrong and, thanks to the fixed nesting order, impossible to compose incorrectly. The call site ends up documenting its own guarantees, so human and agent alike can tell at a glance what a call does in production. And when something misbehaves, the [debug helpers](#debugging) print straight to stdout — exactly where an agent is already looking.

This wrapper also has the support for eventing and inline actions. More on that below.

## Installation

```bash
composer require iak/action
```

## The basics

### Creating an action

An action is a plain class that extends `Action` and implements a `handle()` method. That's the whole contract — no interfaces, no registration:

```php
<?php

namespace App\Actions;

use Iak\Action\Action;

class ChargeOrderAction extends Action
{
    public function handle(Order $order): Payment
    {
        // Your business logic.
    }
}
```

### Generating actions

The package ships a generator:

```bash
php artisan make:action ShipOrder                   # app/Actions/ShipOrder.php
php artisan make:action Orders/ShipOrder            # app/Actions/Orders/ShipOrder.php (App\Actions\Orders)
php artisan make:action ShipOrder --dir=app/Domain  # the namespace follows the directory
php artisan make:action ShipOrder --events          # + a ShipOrderEvent enum wired through #[EmitsEvents]
```

`--force` overwrites existing files. To customize the generated code, place your own `action.stub`, `action.events.stub` or `action-event.stub` in your application's `stubs/` directory — it takes precedence over the package's.

### Running an action

Actions resolve through the container, so constructor injection works like anywhere else in Laravel — inject it, or create one yourself with `make()`:

```php
<?php

// Dependency inject or resolve it directly
$payment = app(ChargeOrderAction::class)->handle($order);

// Or create it using the make() method
$payment = ChargeOrderAction::make()->handle($order);
```

A bare `handle()` call is exactly that — a method call. And chaining a wrapper adds exactly that wrapper's behaviour, nothing else. Observability — the lifecycle events and log context — is its own explicit opt-in via [`observed()`](#lifecycle-events), so adding a retry or an idempotency key never changes what your logs or listeners see.

### Conditional wrappers

Every builder in the package — `make()`, the execution wrappers and `test()` — is conditionable with Laravel's `when()`/`unless()`. That means the if statements around your actions can go away. Instead of this:

```php
if ($user->isActivated()) {
    SendWelcomeGift::make()->handle($user);
}
```

The condition moves into the chain:

```php
SendWelcomeGift::make()
    ->when(fn () => $user->isActivated(), fn (SendWelcomeGift $action) => $action->handle($user));
```

The same trick works for conditioning a single wrapper instead of the whole call — rate-limit the free plan, let paying customers run straight through:

```php
ExportReport::make()
    ->when(fn () => $user->onFreePlan(), fn (ExportReport $action) => $action->throttle("export:{$user->id}", allow: 3, every: 3600))
    ->handle($user);
```

The condition can be a plain value or a closure, and `unless()` is the inverse. When the condition is false, the last call above is a plain bare `handle()` — which is fine, since a wrapper only ever adds its own behaviour. Observability is a separate opt-in via [`observed()`](#lifecycle-events) either way, and it chains fine after a `when()`.

## Inline actions

Sometimes a flow is too small for a class of its own but still deserves the pipeline. `Inline` runs a closure through the same wrappers — no action class required:

```php
use Iak\Action\Inline;

// bare: runs the closure, nothing else
Inline::handle(fn () => $user->sync());

// with wrappers, chained exactly like a class-based action
Inline::idempotent('sync:'.$user->id)
    ->retry(3, backoff: 100)
    ->trace()
    ->handle(fn () => $user->sync());
```

The closure receives the underlying action as its argument, which is how you emit events; declare them at the entry with `events()` — the fluent twin of `#[EmitsEvents]` — and listen with `on()`:

```php
Inline::events(['report.sent'])
    ->on('report.sent', fn ($report) => Mail::send(...))
    ->handle(function ($action) {
        $report = // ... build the report ...
        $action->event('report.sent', $report);

        return $report;
    });
```

Everything on the wrapper works: `retry()`, `fallback()`, `idempotent()` (bust with `Inline::forgetIdempotency($key)`), `circuitBreaker()`, `throttle()`, `withoutOverlapping()`, `memoize()`, `transactional()`, `trace()`/`dumpTrace()`/`ddTrace()`, `wasExecuted()`, `when()`/`unless()`, `defer()` and the `run()` escape hatch.

Two things to know:

- **Inline actions share one class** (`InlineAction`), so idempotency keys share one namespace, log context attributes every inline run as `Iak\Action\InlineAction`, and the wrappers that default their key to the action class — `circuitBreaker()`, `throttle()`, `withoutOverlapping()`, `memoize()` — require an explicit key (you get a clear exception otherwise, never a silently shared circuit breaker).
- **Bare `Inline::handle()` is as silent as any unobserved run** — no lifecycle events, no log context. Start the chain from `Inline::observed()` when you want them, exactly like `observed()` on a class action.

Inline actions don't get constructor injection, `#[EmitsEvents]` ancestor propagation or `Action::test()` mocking and instrumentation. The moment you want those, promote the closure to a real action class — its body moves into `handle()` unchanged.

## Debugging

Every wrapper makes silent decisions — a retry sleeps, a circuit opens, an idempotency key answers from cache. And `handle()` itself does things you can't see from the call site: queries, log writes, memory spikes. The debug helpers make all of it visible right where you are, without touching the action.

They are built for humans and agents alike. Everything below prints straight to stdout — exactly where a coding agent is already looking — so an agent can debug and optimize your actions with the same loop you would use: chain a helper, run the action, read the output, fix the thing. More on that [below](#agents-can-debug-your-actions-too).

### Tracing an execution

Chain `->dumpTrace()` on any call to print a timeline of every decision the wrappers made:

```php
SyncInventory::make()
    ->retry(3, backoff: 400, jitter: true)
    ->idempotent("sku:{$sku->id}")
    ->dumpTrace()
    ->handle($sku);

// +0.0ms  started
// +0.2ms  retry: attempt 1 failed (RuntimeException)
// +0.3ms  retry: sleeping 231ms
// +231.8ms  idempotency: result stored for 'sku-42'
// +232.1ms  completed (232.1ms)
```

`->ddTrace()` does the same but stops the process right after the run, like `dd()`.

When you want the trace programmatically instead of printed, chain `->trace()` and read it back with `lastTrace()`:

```php
$pending = SyncInventory::make()->retry(3, backoff: 400, jitter: true)->idempotent($sku)->trace();

$pending->handle($sku);

echo $pending->lastTrace()->summary();
```

`trace()` also accepts a callback that receives the `Trace` after the run — including when it throws, which is exactly when you want it:

```php
SyncInventory::make()
    ->retry(3)
    ->trace(fn (Trace $trace) => Log::debug($trace->summary()))
    ->handle($sku);
```

A `Trace` holds ordered `TraceEntry` records — the wrapper slot, a `TraceEvent` case, the millisecond offset and the decision's context — with `entries()`, `has()`, `count()`, `first()`, `durationMs()` and `summary()`. On a run that is also `observed()`, the lifecycle events carry the trace too, as `ActionCompleted::$trace` / `ActionFailed::$trace` (null when tracing is off). Tracing costs nothing unless enabled.

### Dumping queries, logs, events and profiles

The [test instruments](#testing) all have dump twins that print what was recorded, without wiring an inspection callback — recording is enabled automatically:

```php
GenerateReport::test()->dumpQueries()->handle($team);

// [queries] 27 recorded (48.3ms total)
// 1. select * from teams where id = ? [0.8ms, mysql]
// 2. select * from members where team_id = ? [1.7ms, mysql]
// 3. select * from tasks where member_id = ? [1.6ms, mysql]
// 4. select * from tasks where member_id = ? [1.7ms, mysql]
// 5. select * from tasks where member_id = ? [1.6ms, mysql]
// ...
```

That's an N+1 staring back at you. Eager-load it, then lock the win in with an assertion so it never creeps back:

```php
it('generates the report without an N+1', function () {
    GenerateReport::test()
        ->assertNoDuplicateQueries()
        ->handle($team);
});
```

Each instrument has a pair: `dumpQueries()`/`ddQueries()`, `dumpLogs()`/`ddLogs()`, `dumpEvents()`/`ddEvents()` and `dumpProfile()`/`ddProfile()`. The `dd*` variants stop the process after printing, like `DB::ddRawSql()`. Like the inspection callbacks, the dumps do not run when an `idempotent()` cache hit means nothing executed.

When combining dump helpers with the query assertions, chain the dumps first: the deferred checks run in chaining order, and a failing assertion stops any dump queued after it from printing.

And when you need to see what a destructive action *would* do, [`dryRun()`](#dry-runs) executes it and rolls the database work back afterwards — the instruments still record and report.

### Log context

Every `observed()` run (and every `test()` run) sets the running action's class under the `action` key in Laravel's [Context](https://laravel.com/docs/context), so log lines written while the action runs — including code it calls — carry which action produced them:

```php
SendInvoice::make()->observed()->handle($order);
// [2026-07-07 12:00:00] production.INFO: invoice created {"action":"App\\Actions\\SendInvoice"}
```

Nested actions attribute to the innermost running action, the previous value is restored afterwards (also when the run throws), and a pre-existing `action` context value survives. Wrapper chains without `observed()` — and bare `$action->handle()` calls — leave the context untouched: `observed()` is the one switch for attribution, so your log shape never changes as a side effect of adding a retry or a lock.

### Agents can debug your actions too

An agent debugging your code has the same problem you do: it can't see inside a run. These helpers fix that for both of you — they print to stdout, the assertions fail with the offending SQL in the message, and nothing needs a debugger or an IDE. That makes actions unusually easy for an agent to debug *and* optimize on its own:

- *"Why is `GenerateReport` slow?"* — the agent chains `->dumpQueries()` and `->dumpProfile()`, reads the output, spots the N+1, fixes it and guards it with `->assertNoDuplicateQueries()`.
- *"Is the retry actually helping?"* — `->dumpTrace()` shows every attempt, sleep and decision on one timeline.
- *"What would this backfill do?"* — `test()->dryRun()` executes it and rolls the database work back, so it can be rehearsed safely.

> **Tip:** mention these helpers in your `CLAUDE.md` / `AGENTS.md`, and your agent will reach for them on its own instead of sprinkling `dump()` calls through your business logic.

## Eventing

Actions can emit events, and anything that calls them can listen. The action announces what happened; whoever cares reacts. Your business logic stays clean and decoupled from the reactions.

### Emitting events

Declare the events an action may emit with the `#[EmitsEvents]` attribute, and emit them with `$this->event()`:

```php
<?php

namespace App\Actions;

use Iak\Action\Action;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['hello_said'])]
class SayHelloAction extends Action
{
    public function handle()
    {
        $result = "Hello";

        $this->event('hello_said', $result);

        return $result;
    }
}
```

The declaration up front is deliberate: it documents what the action can emit, and it catches mistakes early — emitting or listening for an event that isn't declared throws an `InvalidArgumentException` right away, instead of leaving you with a silently dead listener.

### Listening to events

Listen with `on()` before you call `handle()`:

```php
$result = SayHelloAction::make()
    ->on('hello_said', function ($result) {
        Log::info("Hello said: {$result}");
    })
    ->handle();
```

### Enum-backed events

Declare the allowed events with an enum instead of strings — every case becomes an event, and cases work anywhere an event name goes:

```php
enum OrderEvent: string
{
    case Placed = 'order.placed';
    case Shipped = 'order.shipped';
}

#[EmitsEvents(OrderEvent::class)]
class PlaceOrderAction extends Action
{
    public function handle($order)
    {
        // ...
        $this->event(OrderEvent::Placed, $order);

        return $order;
    }
}

PlaceOrderAction::make()
    ->on(OrderEvent::Placed, fn ($order) => Log::info('placed', ['id' => $order->id]))
    ->handle($order);
```

String-backed enums use their value as the event name and pure enums use the case name, so an enum case and its string are interchangeable — existing string listeners keep working. You can also mix cases into the array form: `#[EmitsEvents([OrderEvent::Placed, 'legacy-event'])]`. Int-backed enums are rejected.

### Forwarding events

When you have nested actions, you can use `forwardEvents()` to propagate events from child actions to parent classes that use the `HandlesEvents` trait, even if there are intermediate classes between them. This is particularly useful when services call actions and want to listen to events from those actions.

```php
<?php

namespace App\Services;

use Iak\Action\HandlesEvents;
use Iak\Action\EmitsEvents;

#[EmitsEvents(['email_sent', 'email_failed'])]
class EmailService
{
    use HandlesEvents;

    public function sendWelcomeEmail($user)
    {
        // Call the action with forwardEvents() to propagate events to this service
        SendEmailAction::make()
            ->forwardEvents(['email_sent'])
            ->handle($user);
    }
}
```

```php
<?php

//...

(new EmailService)
    ->on('email_sent', function($user) {
        Log::info('email sent', ['user_id' => $user->id]);
    })
    ->sendWelcomeEmail($user);
```

**How it works:**
- When `forwardEvents()` is called on an action, it captures the classes using the `HandlesEvents` trait that are on the call stack at that moment; events emitted by the action are then forwarded to the nearest captured ancestor
- Call `forwardEvents()` from within the scope that should receive the events (as in the example above); calling it again captures the new scope
- The parent class (service, action, etc.) must also declare the event in its `#[EmitsEvents(...)]` attribute to receive forwarded events
- Events can propagate through multiple layers of intermediate classes, as long as the ancestor class uses the `HandlesEvents` trait

If you call `forwardEvents()` without arguments, all events declared in the action's `#[EmitsEvents(...)]` attribute are forwarded:

```php
SendEmailAction::make()
    ->forwardEvents()  // Forwards all events: ['email_sent', 'email_failed']
    ->handle($user);
```

> Want to assert on the events an action emitted in a test? That's the `events()` instrument — see [Testing events](#testing-events).

## API reference

The rest of the docs walk through the full API with examples. All wrappers chain off `make()` (or `Inline::`), compose freely with each other and with `when()`/`unless()`, and always nest in the same [fixed order](#the-nesting-order-is-fixed) no matter how you chain them.

### Idempotent execution

Run an action at most once per key. The first successful call executes and caches its result; later calls with the same key return the cached result without executing again:

```php
// Executes once. The second call returns the cached result.
ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
```

Choose a key that identifies the unit of work (an order id, a webhook id, a request uuid). The key is used verbatim as the cache key — no prefix, no per-class scoping — so the entry lives under exactly the key you passed, and two actions given the same key share the same entry.

By default the entry is remembered forever on the default cache store. Pass a TTL (seconds, a `DateInterval`, or an expiry `DateTimeInterface`) and/or a cache store name to change that:

```php
// Cache for one hour on the "redis" store.
SendReminder::make()->idempotent("reminder:{$user->id}", 3600, 'redis')->handle($user);
```

Only successful runs consume the key: if `handle()` throws, the exception propagates and the key stays free, so the next call executes again. When the cache store supports locks, concurrent callers are serialised so the action runs only once even under a race.

Bust an entry to allow it to run again:

```php
ChargeCustomer::make()->forgetIdempotency("charge:{$order->id}");
```

> On a persistent store (redis, database, file, …) the action's return value is serialized into the cache, so it must be serializable to be replayed. The `array` store keeps values in memory for the current process only.

#### Consuming a key without caching the result: `once()`

`once()` is `idempotent()` for side effects: run at most once per key, but keep nothing except the key. The first successful run stores a bare `true` under the verbatim cache key; later calls are skipped and answer `null` — there is no result to replay:

```php
SendWelcomeEmail::make()->once("welcome-email:{$user->id}")->handle($user);
SendWelcomeEmail::make()->once("welcome-email:{$user->id}")->handle($user); // skipped, answers null
```

Because the key is checked verbatim, *any* existing cache entry under it counts as consumed — including entries written by other code or other systems. Everything else works like `idempotent()`: only successful runs consume the key, the TTL and store arguments behave the same, concurrent callers are serialised through a lock when the store supports one, and `forgetIdempotency()` frees the key. `wasExecuted()` tells a run apart from a skip — useful since a skip answers `null` and a void-ish action would too.

#### Typed results and autocomplete

The wrapper mirrors your action's own `handle()` signature (via a generic `@mixin`), so PHPStan checks the arguments and infers the return type exactly:

```php
$order = ChargeCustomer::make()->idempotent("charge:{$order->id}")->handle($order);
// PHPStan knows $order is Order, and flags wrong arguments against ChargeCustomer::handle()
```

Some editors don't resolve generic mixins yet for in-editor autocomplete (tracked upstream: [PhpStorm](https://youtrack.jetbrains.com/issue/WI-69638), [Intelephense](https://github.com/bmewburn/vscode-intelephense/issues/3280)). If that's you, `run()` gives the same typing through a closure that receives the action — full autocomplete and an inferred return everywhere, today:

```php
$order = ChargeCustomer::make()
    ->idempotent("charge:{$order->id}")
    ->run(fn (ChargeCustomer $action) => $action->handle($order));
```

Both entry points share the same key and cache entry — pick whichever reads better and switch freely.

#### With the test instruments

Idempotency chains with the [test instruments](#testing) in any order and shares keys with the production wrapper:

```php
$testable = ChargeCustomer::test()
    ->profile(fn ($profiles) => /* ... */)
    ->idempotent("charge:{$order->id}");

$testable->handle($order);

$testable->wasExecuted(); // true on the run that executed, false when served from cache
```

On a cache hit nothing executes, so nothing is instrumented and no inspection callbacks fire — `wasExecuted()` tells the runs apart.

After the run, `assertExecuted()` / `assertSkipped()` turn `wasExecuted()` into proper test failures with named reasons:

```php
$testable = SendInvoice::test()->idempotent('order-7');

$testable->handle($order);
$testable->assertExecuted();

$testable->handle($order);
$testable->assertSkipped();
```

### Retries, fallbacks and circuit breakers

The resilience helpers wrap `handle()` the same way `idempotent()` does, and they all return the same chainable wrapper:

```php
// Re-run a flaky action: up to 3 total attempts, pausing 100ms then 500ms.
SyncInventory::make()->retry(times: 3, backoff: [100, 500])->handle($warehouse);

// Degrade gracefully when the action (still) fails.
$rate = FetchExchangeRate::make()
    ->retry(times: 2)
    ->fallback(fn (Throwable $e) => ExchangeRate::lastKnown($currency))
    ->handle($currency);

// Stop hammering a dependency that keeps failing.
ChargeCustomer::make()
    ->circuitBreaker('stripe', threshold: 5, cooldown: 60)
    ->handle($order);
```

**`retry(times: 3, backoff: 0, when: null)`** re-runs `handle()` when it throws, up to `times` total attempts. `backoff` is the pause between attempts in milliseconds: a fixed value, a per-attempt schedule (`[100, 500]` — the last entry repeats), or a closure receiving the attempt number and the exception. Sleeping goes through Laravel's `Sleep`, so tests control it with `Sleep::fake()`. By default every exception is retried except those implementing the `Iak\Action\Exceptions\NonRetryable` marker interface — implement it on your own exceptions to fail fast, or pass a `when:` closure to decide entirely yourself.

When many processes retry against the same recovering dependency, fixed backoffs arrive in synchronized waves that knock it over again. `jitter: true` spreads them out by sleeping a random duration between zero and the scheduled backoff instead of the exact value:

```php
$action->retry(times: 4, backoff: [100, 400, 800], jitter: true)->handle($order);
```

**`fallback(fn (Throwable $e) => $value)`** answers with a fallback value when the action ultimately throws — including after exhausted retries or on an open circuit breaker. Rethrow from the closure to decline.

**`circuitBreaker(key: null, threshold: 5, cooldown: 60, store: null)`** opens after `threshold` consecutive failures: further calls throw `CircuitOpenException` (with `availableIn()` seconds) without executing, giving the dependency `cooldown` seconds to recover before a single probe is let through. The breaker state is cache-backed and scoped to the action class by default — give the breakers of one shared dependency the same explicit key so they trip together across actions and processes.

### Locks, throttles and transactions

```php
// Never two report generations at once — wait up to 5s for a running one.
GenerateReport::make()->withoutOverlapping("report:{$team->id}", wait: 5)->handle($team);

// At most 10 calls per minute against the mail provider.
SendNewsletter::make()->throttle('mailgun', allow: 10, every: 60)->handle($batch);

// All or nothing, with two shots at a deadlock.
TransferFunds::make()->transactional(attempts: 2)->handle($from, $to, $amount);
```

**`withoutOverlapping(key: null, wait: 0, staleAfter: 60, store: null)`** is the mutex sibling of `idempotent()`: every call runs eventually, just never two at once per key. A held lock makes the call fail immediately — or after waiting up to `wait` seconds — with a `LockTimeoutException`. Nothing is cached. `staleAfter` caps how long a crashed holder can keep the lock. The lock *is* the feature, so a cache store without lock support is rejected loudly.

**`throttle(key: null, allow: 60, every: 60)`** rate-limits executions per key. An exhausted budget throws `ThrottledException` (with `availableIn()` seconds) instead of blocking — composing `retry(backoff: ...)` around a throttled action is the supported way to wait a window out. Nested inside `retry()`, every attempt consumes budget: the throttle protects the dependency behind the action, not the caller.

**`transactional(attempts: 1, connection: null)`** runs `handle()` inside `DB::transaction()`, re-running it up to `attempts` times on a deadlock or serialization failure.

`idempotent()` and `once()` are transaction-aware on their own: when they run inside an open database transaction (on the default connection), the key is only consumed once that transaction commits — rolled-back work leaves the key free to retry.

### Memoization and deferred execution

**`memoize(key: null)`** is per-process idempotency: the first successful result per key is remembered for the rest of the request (in a container-scoped store, so nothing leaks between Octane requests or tests) and later calls return it without executing. The key derives from the `handle()` arguments and is scoped per action class:

```php
// Runs once per request per user, however many places ask.
$permissions = ResolvePermissions::make()->memoize()->handle($user->id);
```

Pass an explicit key when the arguments cannot be serialized, or when executing through `run()` (a closure has no argument list to derive a key from). Flush everything with `Action::flushMemoized()`.

**`defer(fn (MyAction $a) => $a->handle(...))`** runs the action after the response has been sent, via Laravel's `defer()`. The whole configured wrapper chain runs at that point — an idempotency key, throttle budget or breaker state is consumed then, not now:

```php
SendAnalytics::make()
    ->idempotent("pageview:{$request->fingerprint()}")
    ->defer(fn (SendAnalytics $action) => $action->handle($event));
```

### Lifecycle events

Observability is opt-in, and `observed()` is the switch. An observed run dispatches plain Laravel events around the whole invocation: `ActionStarted`, then `ActionCompleted` (with the result, the duration in milliseconds and the memory delta) or `ActionFailed` (with the exception, which is rethrown) — and attributes the run in [log context](#log-context):

```php
ImportUsers::make()->observed()->handle($file);
```

One listener turns every observed action into an APM data point:

```php
Event::listen(function (ActionCompleted $event) {
    Metrics::timing('action.'.class_basename($event->action), $event->durationMs);
});
```

The wrappers never dispatch these on their own — `retry()`, `idempotent()` and friends do exactly their job and stay silent, so adding one to a call changes nothing about what your listeners or logs see. Chain `observed()` next to any combination of wrappers to opt the call in. (A plain `$action->handle()` cannot emit them at all — the base `Action` does not wrap your `handle()`.)

### The nesting order is fixed

Chaining order never changes the semantics. The wrapper always nests the concerns in one documented order:

```
fallback → memoize → idempotent → once → without overlapping → retry → circuit breaker → throttle → transaction → handle()
```

Which reads as: failed attempts never consume an idempotency key (only the first success is cached), every retry attempt consults and feeds the circuit breaker and pays the throttle, an open circuit fails fast instead of being retried, every attempt gets a fresh transaction, and a fallback value is never cached or memoized as a real result.

## Testing

Actions provide helpful static methods for testing and debugging:

```php
// Create a fake for testing
$action = SayHelloAction::fake();

// Create a testable action to help test logs, performance, database queries and more
$action = SayHelloAction::test();
```

> **Heads up — the mock-binding helpers are test-only.**
> `Action::fake()` and `Testable::only()` / `without()` / `except()` bind Mockery
> mocks into the container, so they throw a `RuntimeException` when called outside
> a test context — they run only when `runningUnitTests()` is true or the app
> environment is `local` or `testing`. This stops a forgotten `fake()` from
> silently replacing real actions with mocks in staging or production. If you
> genuinely need them elsewhere, opt in explicitly with `Action::allowTestHelpers()`.
>
> The observability helpers — `test()->profile()`, `->queries()` and `->logs()` —
> are **not** guarded. They are Mockery-free and restore the container to its
> previous state after running, so they are the supported way to profile or
> inspect an action in production.

### Basic testing

```php
<?php

use App\Actions\SayHelloAction;

it('says hello', function () {
    $result = SayHelloAction::make()->handle();

    expect($result)->toBe('Hello');
});

it('can fake an action', function () {
    $action = SayHelloAction::fake();

    expect($action)->toBeInstanceOf(MockInterface::class);
});
```

`Testable` mirrors your action's own `handle()` signature through a generic `@mixin`, so PHPStan checks the arguments and infers the return type. Editors that don't resolve generic mixins yet can get the same typing through a closure:

```php
$result = SayHelloAction::test()
    ->run(fn (SayHelloAction $action) => $action->handle());
```

### Mocking actions in tests

When testing actions that call other actions, you can control which actions execute their real logic and which are mocked.

#### The `only()` method

The `only()` method specifies which actions should **execute normally**. All other actions will be automatically mocked.

```php
use App\Actions\ProcessOrderAction;
use App\Actions\CalculateTaxAction;
use App\Actions\ChargeCustomerAction;
use App\Actions\SendEmailAction;

it('only executes specific actions', function () {
    ProcessOrderAction::test()
        ->only([ChargeCustomerAction::class, CalculateTaxAction::class])
        ->handle(function () {
            // ChargeCustomerAction executes normally
            // CalculateTaxAction executes normally
            // SendEmailAction is automatically mocked
        });
});
```

You can also specify a single action:

```php
it('allows only one action to execute', function () {
    ProcessOrderAction::test()
        ->only(ChargeCustomerAction::class)
        ->handle();
});
```

#### The `without()` method

The `without()` method mocks specific actions, preventing them from executing their real `handle()` method. All other actions execute normally.

```php
it('mocks specific actions', function () {
    ProcessOrderAction::test()
        ->without(SendEmailAction::class)
        ->handle(function () {
            // ChargeCustomerAction executes normally
            // CalculateTaxAction executes normally
            // SendEmailAction is mocked
        });
});
```

You can mock multiple actions:

```php
it('mocks multiple actions', function () {
    ProcessOrderAction::test()
        ->without([SendEmailAction::class, ChargeCustomerAction::class])
        ->handle();
});
```

You can also specify return values for mocked actions:

```php
it('mocks actions with custom return values', function () {
    $result = ProcessOrderAction::test()
        ->without([
            CalculateTaxAction::class => 10.50,
            SendEmailAction::class => true,
        ])
        ->handle();
});
```

#### The `except()` method

The `except()` method is an alias for `without()`, providing an alternative syntax that may be more readable in certain contexts.

### Testing database queries

The `queries()` method allows you to record and inspect database queries executed during action execution. This can be really helpful when debugging performance issues, n+1 queries and more.

```php
use App\Actions\ProcessOrderAction;
use Illuminate\Support\Facades\DB;

it('executes the correct database queries', function () {
    ProcessOrderAction::test()
        ->queries(function ($queries) {
            expect($queries)->toHaveCount(2);
            expect($queries[0]->query)->toContain('INSERT INTO orders');
            expect($queries[1]->query)->toContain('UPDATE inventory');
            expect($queries[0]->action)->toBe(ProcessOrderAction::class);
        })
        ->handle($orderData);
});
```

To track queries for a specific nested action:

```php
it('tracks queries from nested actions', function () {
    ProcessOrderAction::test()
        ->queries(CalculateTaxAction::class, function ($queries) {
            expect($queries)->toHaveCount(1);
            expect($queries[0]->query)->toContain('SELECT');
        })
        ->handle($orderData);
});
```

#### Asserting query counts and N+1s

Chain the query assertions before `handle()` — recording is enabled automatically and the checks run once the action completes, failing the test with the offending SQL:

```php
it('does not produce duplicate queries', function () {
    ProcessOrderAction::test()
        ->assertNoDuplicateQueries()
        ->assertQueryCount(5)
        ->handle($orderData);
});
```

Duplicates are grouped by normalized SQL, so an N+1 loop and `whereIn` queries with different placeholder counts are caught.

### Testing events

Record the events an action emits with the `events()` instrument — the same shapes as `queries()`/`logs()`:

```php
it('emits the placed event', function () {
    PlaceOrderAction::test()
        ->events(function ($events) {
            expect($events)->toHaveCount(1);
            expect($events->first()->is(OrderEvent::Placed))->toBeTrue();
            expect($events->first()->data)->toBe($order);
        })
        ->handle($order);
});

// Or record events of nested actions resolved during the run:
it('emits from nested actions', function () {
    ProcessOrderAction::test()
        ->events(PlaceOrderAction::class, function ($events) {
            expect($events->first()->name)->toBe('order.placed');
        })
        ->handle($orderData);
});
```

### Testing logs

The `logs()` method allows you to capture and verify log entries written during action execution:

```php
use App\Actions\ProcessOrderAction;
use Illuminate\Support\Facades\Log;

it('logs important events', function () {
    ProcessOrderAction::test()
        ->logs(function ($logs) {
            expect($logs)->toHaveCount(2);
            expect($logs[0]->level)->toBe('INFO');
            expect($logs[0]->message)->toBe('Order processing started');
            expect($logs[1]->level)->toBe('ERROR');
            expect($logs[1]->message)->toBe('Payment failed');
            expect($logs[0]->context)->toBeArray();
        })
        ->handle($orderData);
});
```

To track logs from a specific nested action:

```php
it('tracks logs from nested actions', function () {
    ProcessOrderAction::test()
        ->logs(SendEmailAction::class, function ($logs) {
            expect($logs)->toHaveCount(1);
            expect($logs[0]->message)->toBe('Email sent successfully');
        })
        ->handle($orderData);
});
```

### Profiling actions

The `profile()` method allows you to measure execution time, memory usage, and track memory records:

```php
use App\Actions\ProcessOrderAction;

it('profiles action performance', function () {
    ProcessOrderAction::test()
        ->profile(function ($profiles) {
            expect($profiles)->toHaveCount(1);
            expect($profiles[0]->class)->toBe(ProcessOrderAction::class);
            expect($profiles[0]->duration()->totalMilliseconds)->toBeLessThan(100);
            expect($profiles[0]->memoryUsed()->bytes())->toBeGreaterThan(0);
        })
        ->handle($orderData);
});
```

You can also track memory points during execution:

```php
it('tracks memory usage at specific points', function () {
    ProcessOrderAction::test()
        ->profile(function ($profiles) {
            $records = $profiles[0]->records();
            expect($records)->toHaveCount(2);
            expect($records[0]->name)->toBe('before-processing');
            expect($records[1]->name)->toBe('after-processing');
        })
        ->handle(function ($action) {
            $action->recordMemory('before-processing');
            // ... do work ...
            $action->recordMemory('after-processing');
        });
});
```

To profile specific nested actions:

```php
it('profiles nested actions', function () {
    ProcessOrderAction::test()
        ->profile([CalculateTaxAction::class, ApplyDiscountAction::class], function ($profiles) {
            expect($profiles)->toHaveCount(2);
            expect($profiles[0]->class)->toBe(CalculateTaxAction::class);
            expect($profiles[1]->class)->toBe(ApplyDiscountAction::class);
        })
        ->handle($orderData);
});
```

### Dry runs

`dryRun()` answers "what would this action do": `handle()` runs inside a database transaction that is rolled back afterwards, while the instruments still record and report and the result is still returned:

```php
$report = GenerateInvoices::test()
    ->queries(fn ($queries) => dump($queries))
    ->dryRun()
    ->handle($period);

// Every INSERT/UPDATE the action performed is now rolled back.
```

Pass connection names to wrap more than the default connection: `dryRun('tenant', 'shared')`. Chained with `idempotent()`, the key persisted during a dry run is discarded with the rollback — a rehearsal never blocks the real run.

> Only database work is contained. Mail, HTTP calls and cache writes made by the action escape a dry run — it is a diagnostic tool, not a sandbox.

### Combining features

You can combine multiple testing features in a single test:

```php
it('tracks queries, logs, and performance', function () {
    ProcessOrderAction::test()
        ->queries(function ($queries) {
            expect($queries)->toHaveCount(3);
        })
        ->logs(function ($logs) {
            expect($logs)->toHaveCount(2);
        })
        ->profile(function ($profiles) {
            expect($profiles)->toHaveCount(1);
            expect($profiles[0]->duration()->totalMilliseconds)->toBeLessThan(50);
        })
        ->handle($orderData);
});
```

## Requirements

- PHP 8.2+
- Laravel 11.0+ or 12.0+

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Credits

- [Isak Berglind](https://github.com/iaK)
- [All Contributors](https://github.com/iaK/action/contributors)

## Support

If you discover any issues or have questions, please [open an issue](https://github.com/iaK/action/issues).
