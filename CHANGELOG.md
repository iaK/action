# Changelog

All notable changes to `laravel-action` will be documented in this file.

## Unreleased

**New features**

- Concurrency and transaction wrappers on the same chain: `$action->withoutOverlapping($key, $wait, $staleAfter, $store)` gives mutex semantics per key — every call runs eventually, never two at once; a held lock fails immediately (or after waiting `$wait` seconds) with a `LockTimeoutException`, and lock-less cache stores are rejected loudly. `$action->throttle($key, $allow, $every)` rate-limits executions per key through Laravel's `RateLimiter`, throwing a `ThrottledException` (carrying `availableIn()`) once the budget is exhausted — deliberately retryable, so `retry(backoff:)` around it waits a window out while every attempt inside `retry()` consumes budget. `$action->transactional($attempts, $connection)` wraps the run in `DB::transaction()` with deadlock-retry attempts, nested innermost so every retry attempt gets a fresh transaction. Keys default to the action class.
- Resilience wrappers, chainable with `idempotent()` on one wrapper (`PendingAction`): `$action->retry($times, $backoff, $when)` re-runs a failing action with a fixed, per-attempt or closure-computed backoff (slept through `Sleep`, so tests fake it) — the `NonRetryable` marker interface opts exceptions out of retrying and an explicit `$when` filter overrules it; `$action->fallback(fn (Throwable $e) => ...)` answers with a degraded value when the action ultimately throws; `$action->circuitBreaker($key, $threshold, $cooldown, $store)` fails fast with a `CircuitOpenException` (carrying `availableIn()`) after too many consecutive failures and lets a single lock-guarded probe through once the cooldown passes. The nesting order is fixed regardless of chaining order — fallback → idempotent → retry → circuit breaker — so failed attempts never consume an idempotency key, every attempt feeds the breaker, an open circuit is not retried, and a fallback value is never cached as a real result.
- Idempotent execution: `$action->idempotent($key, $ttl = null, $store = null)->handle(...)` runs the action at most once per key and returns the cached result on later calls. Keys are scoped per action class, results are stored in an envelope so a `null`/`false`/`''` result still counts as executed, only successful runs consume the key (a thrown exception leaves it free to retry), and concurrent callers are serialised with a cache lock when the store supports one. Bust an entry with `$action->forgetIdempotency($key, $store = null)`. The wrapper mirrors the action's own `handle()` signature via a generic `@mixin` (typed arguments and inferred return in PHPStan); `run(fn (MyAction $a) => $a->handle(...))` offers the same typing through a closure for editors that don't resolve generic mixins yet. Chainable with the test instruments — `MyAction::test()->profile(...)->idempotent($key)->handle(...)` — sharing keys with the production wrapper; on a cache hit nothing executes, nothing is instrumented and no callbacks fire (`wasExecuted()` tells the runs apart). Inside an open database transaction on the default connection, the idempotency key is only consumed once the transaction commits — rolled-back work leaves the key free.

**Breaking changes**

- The mock-binding test helpers — `Action::fake()` and `Testable::only()`/`without()`/`except()` — now throw a `RuntimeException` when used outside a test context (`runningUnitTests()` or the `local`/`testing` environments), instead of silently binding mocks into the container. A forgotten helper in staging/production previously replaced real actions with mocks (worst under Octane). To profile or debug in production, use the ungated observability helpers (`test()->profile()/queries()/logs()`); to deliberately bind mocks outside a test environment, opt in with `Action::allowTestHelpers()`.
- `Profile::memoryUsed()`, `startMemory()`, `endMemory()` and `peakMemory()` no longer take a unit argument and now return a `MemorySize` value object. Use `->bytes()`, `->in('KB')` or `->format()` on the result. This also fixes fractional unit conversions being silently truncated to integers.
- `Memory::formattedMemory()` was replaced by `Memory::size()`, returning a `MemorySize`.
- `MemoryFormatter` was replaced by the `MemorySize` value object.
- `only()`, `without()`, `except()`, `profile()`, `queries()` and `logs()` now reject container aliases and non-action classes with a clear exception instead of failing later with a fatal error.
- Event forwarding now resolves its ancestors when `forwardEvents()` is called instead of on every `event()` emission. Call `forwardEvents()` from within the scope that should receive the events — the idiomatic `Action::make()->forwardEvents()->handle()` chain is unaffected, but an action configured in one scope and executed in another now propagates to the ancestors that enclosed the `forwardEvents()` call (calling it again re-captures), no longer to whatever encloses `handle()`. Captured ancestors are held weakly, so forwarding never keeps a service alive, and emitting events no longer walks the call stack.

**Improvements**

- Event propagation no longer captures call-stack arguments, and the per-call trait and attribute reflection (`usesHandlesEvents()`, `getAllowedEvents()`) is now cached per class.
- PHPStan level 9 (up from 6) with full generics: `Action::test()` returns `Testable<static>`, `Action::fake()` returns `static&MockInterface`, and all inspection callbacks have typed closure signatures, so editors autocomplete `$queries`, `$logs` and `$profiles` inside callbacks.
- `only()` now also mocks constructor-injected child actions, not just actions resolved during `handle()`.
- Auto-mocked actions return a zero value matching their declared `handle()` return type (`''`, `0`, `false`, `[]`) instead of `null`.
- Declared the runtime dependencies (`illuminate/support`, `illuminate/database`, `monolog/monolog`, `nesbot/carbon`) and suggested `mockery/mockery` for the testing helpers.
- The `profile()`/`queries()`/`logs()` test instruments now share one generic `Instrumentation` descriptor internally instead of three copies of the registration, interception and collection machinery — groundwork for future instruments.
- Every collection path — nested-action proxies and the action under test alike — now routes through the overridable `addProfile()`/`addQueries()`/`addLogs()` hooks, so a `Testable` subclass overriding them sees all collected results. A listener mispaired with its instrumentation descriptor now throws a `LogicException` instead of silently reading no results.

**Bug fixes**

- Falsy mocked return values (`false`, `0`, `''`) passed to `without()` are no longer silently converted to `null`.
- Fixed a fatal error when profiling or auto-mocking actions whose `handle()` declares a return type.
- Fixed a crash in the event-cleanup destructor when it fired between tests while facades pointed at a flushed application.
- Container bindings replaced by `profile()`/`queries()`/`logs()` proxies are restored after `handle()`, and the `only()` hook deactivates itself instead of intercepting resolutions (and leaking the `Testable`) forever.
- `profile()`/`queries()`/`logs()` now reject `final` action classes with a clear exception instead of an uncatchable fatal when the proxy is created.
- The `final`-class guard also applies to `queries($callback)`: that path additionally registers the action under test as a proxy target, so a `final` self-resolving action previously fataled when re-resolved mid-run instead of failing at registration.
- `ProfileListener` removes its `action.record_memory.*` listeners after the profiled run instead of leaking them.

## v1.3.0 - 2025-11-04

Added with(), without(), profile(), queries(), logs() as well as the ability to forward events.
