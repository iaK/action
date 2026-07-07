<?php

namespace Iak\Action\Testing;

use Closure;
use DateInterval;
use DateTimeInterface;
use Iak\Action\Action;
use Iak\Action\ActionContext;
use Iak\Action\PendingAction;
use Iak\Action\Support\Dumper;
use Iak\Action\Testing\Results\EmittedEvent;
use Iak\Action\Testing\Results\Entry;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Testing\Results\Query;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\CompositeExpectation;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * @template TAction of Action
 *
 * @mixin TAction
 */
class Testable
{
    use Conditionable;

    /**
     * @param  TAction  $action
     */
    public function __construct(
        public Action $action
    ) {
        // Array order defines handle()'s wrapping order: first entry innermost (profile),
        // last outermost (events). This ordering is pinned by tests.
        $this->instruments = [
            'profile' => new Instrumentation(
                static fn (Action $action, Action $eventSource): ProfileListener => new ProfileListener($action, $eventSource),
                static fn (Listener $listener): array => $listener instanceof ProfileListener
                    ? [$listener->getProfile()]
                    : throw new LogicException('The profile instrumentation cannot read results from a '.$listener::class.'.'),
                static function (Testable $testable, array $results): void {
                    foreach (self::ensureResults(Profile::class, $results) as $profile) {
                        $testable->addProfile($profile);
                    }
                },
            ),
            'queries' => new Instrumentation(
                static fn (Action $action, Action $eventSource): QueryListener => new QueryListener($action::class),
                static fn (Listener $listener): array => $listener instanceof QueryListener
                    ? $listener->getQueries()
                    : throw new LogicException('The queries instrumentation cannot read results from a '.$listener::class.'.'),
                static function (Testable $testable, array $results): void {
                    $testable->addQueries(self::ensureResults(Query::class, $results));
                },
            ),
            'logs' => new Instrumentation(
                static fn (Action $action, Action $eventSource): LogListener => new LogListener($action::class),
                static fn (Listener $listener): array => $listener instanceof LogListener
                    ? $listener->getLogs()
                    : throw new LogicException('The logs instrumentation cannot read results from a '.$listener::class.'.'),
                static function (Testable $testable, array $results): void {
                    $testable->addLogs(self::ensureResults(Entry::class, $results));
                },
            ),
            'events' => new Instrumentation(
                static fn (Action $action, Action $eventSource): EventListener => new EventListener($action, $eventSource),
                static fn (Listener $listener): array => $listener instanceof EventListener
                    ? $listener->getEvents()
                    : throw new LogicException('The events instrumentation cannot read results from a '.$listener::class.'.'),
                static function (Testable $testable, array $results): void {
                    $testable->addEvents(self::ensureResults(EmittedEvent::class, $results));
                },
            ),
        ];
    }

    /**
     * Guarantee results routed to a typed add hook are of that hook's result
     * class. The listener/descriptor pairing is an internal invariant already
     * enforced by readResults, so a mismatch here is a bug, never user error.
     *
     * @template TExpected of object
     *
     * @param  class-string<TExpected>  $class
     * @param  array<int, mixed>  $results
     * @return array<int, TExpected>
     */
    protected static function ensureResults(string $class, array $results): array
    {
        foreach ($results as $result) {
            if (! $result instanceof $class) {
                throw new LogicException(
                    'Instrumentation results must be instances of '.$class.', got '.get_debug_type($result).'.'
                );
            }
        }

        /** @var array<int, TExpected> $results */
        return $results;
    }

    /** @var array<string, Instrumentation<Listener, mixed>> */
    protected array $instruments;

    /** @var array<int, class-string<Action>> */
    protected array $only = [];

    /**
     * Deferred post-run hooks — query assertions and dump reporters. They run
     * after the instruments have reported, and never run when an idempotent()
     * cache hit means nothing executed.
     *
     * @var array<int, Closure(): void>
     */
    protected array $postRunChecks = [];

    /** @var array<class-string<Action>, array{concrete: mixed, shared: bool}|null> */
    protected array $replacedBindings = [];

    /** @var PendingAction<TAction>|null */
    protected ?PendingAction $idempotency = null;

    /**
     * Whether handle() runs inside rolled-back database transactions.
     */
    protected bool $dryRun = false;

    /**
     * The connections a dry run wraps in transactions; null means the
     * default connection.
     *
     * @var array<int, string|null>
     */
    protected array $dryRunConnections = [null];

    protected bool $interceptingOnly = false;

    protected bool $onlyHookRegistered = false;

    protected bool $resolvingMainAction = false;

    /**
     * Mock specific actions, preventing them from executing their real
     * handle() method. All other actions execute normally.
     *
     * Accepts a class name, a list of class names, a map of class names to
     * mocked return values, or an already prepared Mockery mock.
     *
     * @param  class-string<Action>|MockInterface|LegacyMockInterface|CompositeExpectation|array<class-string<Action>|int, mixed>  $classes
     */
    public function without(string|object|array $classes): static
    {
        Action::guardTestHelpers('Testable::without()');

        $classes = is_array($classes) ? $classes : [$classes];

        foreach ($classes as $key => $class) {
            [$class, $returnValue] = $this->getClassAndReturnValue($class, $key);

            if ($class instanceof CompositeExpectation
                || $class instanceof MockInterface
                || $class instanceof LegacyMockInterface) {
                continue;
            }

            $expectation = $this->resolveActionClass($class)::fake()->shouldReceive('handle');

            if ($returnValue !== null) {
                $expectation->andReturn($returnValue);
            }
        }

        return $this;
    }

    /**
     * Alias for {@see without()}.
     *
     * Mocks specific actions, preventing them from executing their real `handle()` method.
     * All other actions execute normally.
     *
     * @param  class-string<Action>|MockInterface|LegacyMockInterface|CompositeExpectation|array<class-string<Action>|int, mixed>  $classes
     */
    public function except(string|object|array $classes): static
    {
        Action::guardTestHelpers('Testable::except()');

        return $this->without($classes);
    }

    /**
     * Specify which actions should execute normally. All other actions
     * resolved while the action under test runs are mocked automatically.
     *
     * @param  class-string<Action>|array<int, class-string<Action>>  $classes
     */
    public function only(string|array $classes): static
    {
        Action::guardTestHelpers('Testable::only()');

        $classes = is_array($classes) ? $classes : [$classes];

        $this->only = array_map(
            fn (mixed $class): string => $this->resolveActionClass($class),
            $classes
        );

        return $this;
    }

    /**
     * Profile the action under test, or specific nested actions.
     *
     * @param  (Closure(Collection<int, Profile>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Profile>): void)|null  $callback
     */
    public function profile(Closure|string|array $actions, ?Closure $callback = null): static
    {
        return $this->register($this->instruments['profile'], $actions, $callback);
    }

    /**
     * Record database queries executed by the action under test, or by
     * specific nested actions.
     *
     * @param  (Closure(Collection<int, Query>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Query>): void)|null  $callback
     */
    public function queries(Closure|string|array $actions, ?Closure $callback = null): static
    {
        $this->register($this->instruments['queries'], $actions, $callback);

        // When only a callback is given, the action under test is also
        // registered as a nested proxy target. The binding is reachable if the
        // action is re-resolved from the container during the run (e.g. a
        // self-resolving action); it is preserved here for exact behavioral parity.
        if ($callback === null && $actions instanceof Closure) {
            $this->instruments['queries']->actions = [$this->resolveProxyableActionClass($this->action::class)];
        }

        return $this;
    }

    /**
     * Record log entries written by the action under test, or by specific
     * nested actions.
     *
     * @param  (Closure(Collection<int, Entry>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, Entry>): void)|null  $callback
     */
    public function logs(Closure|string|array $actions, ?Closure $callback = null): static
    {
        return $this->register($this->instruments['logs'], $actions, $callback);
    }

    /**
     * Record events emitted by the action under test, or by specific nested
     * actions. Events propagated into an instrumented action are re-emitted
     * under its own name and therefore recorded as its own.
     *
     * @param  (Closure(Collection<int, EmittedEvent>): void)|class-string<Action>|array<int, class-string<Action>>  $actions
     * @param  (Closure(Collection<int, EmittedEvent>): void)|null  $callback
     */
    public function events(Closure|string|array $actions, ?Closure $callback = null): static
    {
        return $this->register($this->instruments['events'], $actions, $callback);
    }

    /**
     * Assert that no query recorded during the run executed more than once,
     * grouping by connection and normalized SQL (whitespace collapsed,
     * placeholder lists reduced), so classic N+1 patterns fail the test.
     * Enables query recording for the action under test; the check runs
     * after handle() completes and covers everything the queries instrument
     * recorded, nested proxies included. Like the inspection callbacks, it
     * does not run when the idempotency cache serves the result.
     */
    public function assertNoDuplicateQueries(): static
    {
        $this->instruments['queries']->wrapMainAction = true;

        $this->postRunChecks[] = function (): void {
            $duplicates = (new Collection($this->recordedQueries()))
                ->groupBy(fn (Query $query): string => $query->connection.'|'.$query->normalizedSql())
                ->filter(fn (Collection $group): bool => $group->count() > 1);

            if ($duplicates->isEmpty()) {
                return;
            }

            $lines = $duplicates
                ->map(fn (Collection $group): string => '['.$group->count().'x] '.$group->first()?->normalizedSql())
                ->values()
                ->implode(PHP_EOL);

            $this->failAssertion('Expected no duplicate queries, but found:'.PHP_EOL.$lines);
        };

        return $this;
    }

    /**
     * Assert that exactly the given number of queries was recorded during the
     * run. Enables query recording for the action under test; the check runs
     * after handle() completes and covers everything the queries instrument
     * recorded, nested proxies included. Like the inspection callbacks, it
     * does not run when the idempotency cache serves the result.
     */
    public function assertQueryCount(int $count): static
    {
        $this->instruments['queries']->wrapMainAction = true;

        $this->postRunChecks[] = function () use ($count): void {
            $queries = $this->recordedQueries();

            if (count($queries) === $count) {
                return;
            }

            $sql = array_map(fn (Query $query): string => '- '.$query->query, $queries);

            $this->failAssertion(
                'Expected exactly '.$count.' queries, '.count($queries).' recorded:'
                .PHP_EOL.implode(PHP_EOL, $sql)
            );
        };

        return $this;
    }

    /**
     * Print every query the run recorded, once the action completes. Enables
     * query recording for the action under test automatically — no queries()
     * registration needed. Like the inspection callbacks, the report is
     * skipped when the idempotency cache serves the result.
     *
     * @return $this
     */
    public function dumpQueries(): static
    {
        return $this->queueDump('queries', fn (): string => $this->formatQueries(), dd: false);
    }

    /**
     * dumpQueries(), then stop the process — mirroring DB::ddRawSql().
     *
     * @return $this
     */
    public function ddQueries(): static
    {
        return $this->queueDump('queries', fn (): string => $this->formatQueries(), dd: true);
    }

    /**
     * Print every log entry the run recorded, once the action completes.
     * Enables log recording for the action under test automatically — no
     * logs() registration needed. Like the inspection callbacks, the report
     * is skipped when the idempotency cache serves the result.
     *
     * @return $this
     */
    public function dumpLogs(): static
    {
        return $this->queueDump('logs', fn (): string => $this->formatLogs(), dd: false);
    }

    /**
     * dumpLogs(), then stop the process — mirroring DB::ddRawSql().
     *
     * @return $this
     */
    public function ddLogs(): static
    {
        return $this->queueDump('logs', fn (): string => $this->formatLogs(), dd: true);
    }

    /**
     * Print every event the run emitted, once the action completes. Enables
     * event recording for the action under test automatically — no events()
     * registration needed. Like the inspection callbacks, the report is
     * skipped when the idempotency cache serves the result.
     *
     * @return $this
     */
    public function dumpEvents(): static
    {
        return $this->queueDump('events', fn (): string => $this->formatEvents(), dd: false);
    }

    /**
     * dumpEvents(), then stop the process — mirroring DB::ddRawSql().
     *
     * @return $this
     */
    public function ddEvents(): static
    {
        return $this->queueDump('events', fn (): string => $this->formatEvents(), dd: true);
    }

    /**
     * Print the profile the run recorded, once the action completes. Enables
     * profiling for the action under test automatically — no profile()
     * registration needed. Like the inspection callbacks, the report is
     * skipped when the idempotency cache serves the result.
     *
     * @return $this
     */
    public function dumpProfile(): static
    {
        return $this->queueDump('profile', fn (): string => $this->formatProfiles(), dd: false);
    }

    /**
     * dumpProfile(), then stop the process — mirroring DB::ddRawSql().
     *
     * @return $this
     */
    public function ddProfile(): static
    {
        return $this->queueDump('profile', fn (): string => $this->formatProfiles(), dd: true);
    }

    /**
     * @return array<int, Query>
     */
    protected function recordedQueries(): array
    {
        return self::ensureResults(Query::class, $this->instruments['queries']->results());
    }

    /**
     * Enable the given instrument for the main action and queue a post-run
     * reporter that prints its formatted report — through dd() when $dd,
     * stopping the process.
     *
     * @param  Closure(): string  $format
     * @return $this
     */
    protected function queueDump(string $instrument, Closure $format, bool $dd): static
    {
        $this->instruments[$instrument]->wrapMainAction = true;

        $this->postRunChecks[] = static function () use ($format, $dd): void {
            $dumper = app(Dumper::class);
            $report = $format();

            $dd ? $dumper->dd($report) : $dumper->dump($report);
        };

        return $this;
    }

    /** @return array<int, Entry> */
    protected function recordedLogs(): array
    {
        return self::ensureResults(Entry::class, $this->instruments['logs']->results());
    }

    /** @return array<int, EmittedEvent> */
    protected function recordedEvents(): array
    {
        return self::ensureResults(EmittedEvent::class, $this->instruments['events']->results());
    }

    /** @return array<int, Profile> */
    protected function recordedProfiles(): array
    {
        return self::ensureResults(Profile::class, $this->instruments['profile']->results());
    }

    protected function formatQueries(): string
    {
        $queries = $this->recordedQueries();
        $total = round(array_sum(array_map(static fn (Query $query): float => $query->time, $queries)), 1);

        $lines = ['[queries] '.count($queries).' recorded ('.$total.'ms total)'];

        foreach ($queries as $index => $query) {
            $lines[] = ($index + 1).'. '.$query->query.' ['.round($query->time, 1).'ms, '.$query->connection.']';
        }

        return implode(PHP_EOL, $lines);
    }

    protected function formatLogs(): string
    {
        $logs = $this->recordedLogs();

        $lines = ['[logs] '.count($logs).' recorded'];

        foreach ($logs as $index => $entry) {
            $lines[] = ($index + 1).'. ['.strtolower($entry->level).'] '.$entry->message
                .($entry->context === [] ? '' : ' '.self::encode($entry->context));
        }

        return implode(PHP_EOL, $lines);
    }

    protected function formatEvents(): string
    {
        $events = $this->recordedEvents();

        $lines = ['[events] '.count($events).' emitted'];

        foreach ($events as $index => $event) {
            $lines[] = ($index + 1).'. '.$event->name
                .($event->data === null ? '' : ' '.self::encode($event->data));
        }

        return implode(PHP_EOL, $lines);
    }

    protected function formatProfiles(): string
    {
        $profiles = $this->recordedProfiles();

        $lines = ['[profile] '.count($profiles).' recorded'];

        foreach ($profiles as $index => $profile) {
            $lines[] = ($index + 1).'. '.$profile->class.': '
                .round((float) $profile->duration()->totalMilliseconds, 1).'ms, '
                .'memory '.$profile->memoryUsed()->format().' (peak '.$profile->peakMemory()->format().')';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * JSON for the report lines; the debug type when a payload cannot be
     * encoded (closures, resources).
     */
    protected static function encode(mixed $value): string
    {
        $json = json_encode($value);

        return $json === false ? get_debug_type($value) : $json;
    }

    /**
     * Fail a deferred query assertion: through PHPUnit when it is installed
     * (a proper test failure), otherwise a plain AssertionError - the Testing
     * namespace must stay usable on the supported profile-in-prod path where
     * PHPUnit is absent.
     */
    protected function failAssertion(string $message): never
    {
        if (class_exists(Assert::class)) {
            Assert::fail($message);
        }

        throw new \AssertionError($message);
    }

    /**
     * Shared registration logic for the profile()/queries()/logs() registrars:
     * capture a callback for the action under test, or resolve a list of nested
     * actions to instrument.
     *
     * The exact generic callback signatures are declared on the public
     * registrars; here they are handled as bare Closures.
     *
     * @param  Instrumentation<Listener, mixed>  $instrument
     * @param  Closure|class-string<Action>|array<int, class-string<Action>>  $actions
     */
    protected function register(Instrumentation $instrument, Closure|string|array $actions, ?Closure $callback): static
    {
        if (is_null($callback) && $actions instanceof Closure) {
            $instrument->callback = $actions;
            $instrument->wrapMainAction = true;

            return $this;
        }

        if (is_null($callback)) {
            throw new InvalidArgumentException('A callback is required');
        }

        $actions = is_array($actions) ? $actions : [$actions];

        $instrument->actions = array_map(
            fn (mixed $action): string => $this->resolveProxyableActionClass($action),
            $actions
        );

        $instrument->callback = $callback;

        return $this;
    }

    /**
     * Run handle() at most once per idempotency key, chainable with the
     * instruments. On a cache hit nothing executes, so nothing is instrumented
     * and no inspection callbacks fire — wasExecuted() tells the runs apart.
     * Keys are shared with the production idempotent() wrapper.
     *
     * @return $this
     */
    public function idempotent(string $key, DateInterval|DateTimeInterface|int|null $ttl = null, ?string $store = null): static
    {
        $this->idempotency = (new PendingAction($this->action))->idempotent($key, $ttl, $store);

        return $this;
    }

    /**
     * Whether the action ran on the last handle() call: null when idempotent()
     * is not configured (or before handle() runs), true if it executed, false
     * if the result was served from the idempotency cache.
     */
    public function wasExecuted(): ?bool
    {
        return $this->idempotency?->wasExecuted();
    }

    /**
     * Assert that the last handle() actually ran the action instead of
     * serving the idempotency cache. Post-hoc, like Laravel's fakes: act,
     * then assert. Requires idempotent() and a completed run.
     *
     * @return $this
     */
    public function assertExecuted(): static
    {
        $executed = $this->wasExecuted();

        if ($executed === null) {
            $this->failAssertion(
                'Cannot assert the action executed: idempotent() was not configured or handle() has not run yet.'
            );
        }

        if ($executed === false) {
            $this->failAssertion(
                'Expected the action to execute, but the result was served from the idempotency cache.'
            );
        }

        return $this;
    }

    /**
     * Assert that the last handle() was served from the idempotency cache
     * and the action never ran. The mirror image of assertExecuted().
     *
     * @return $this
     */
    public function assertSkipped(): static
    {
        $executed = $this->wasExecuted();

        if ($executed === null) {
            $this->failAssertion(
                'Cannot assert the action was skipped: idempotent() was not configured or handle() has not run yet.'
            );
        }

        if ($executed === true) {
            $this->failAssertion(
                'Expected the result to be served from the idempotency cache, but the action executed.'
            );
        }

        return $this;
    }

    /**
     * Run handle() inside database transactions that are rolled back
     * afterwards: "what would this action do". The instruments still record
     * and report, the result is still returned — only the database work is
     * discarded (on the default connection unless names are given). Anything
     * transaction-aware rolls back with it: an idempotency key persisted
     * during a dry run is discarded too. Side effects outside the database
     * (mail, HTTP, cache writes by the action itself) are NOT contained.
     *
     * @return $this
     */
    public function dryRun(string ...$connections): static
    {
        $this->dryRun = true;

        if ($connections !== []) {
            $this->dryRunConnections = array_values($connections);
        }

        return $this;
    }

    /**
     * Intercept handle() and forward any other method call to the wrapped
     * action. handle() itself is virtual on purpose: the class-level
     * `@mixin TAction` gives it the wrapped action's real signature in tools
     * that resolve generic mixins, which a declared handle(mixed ...$args)
     * would shadow. __call only fires for undefined methods, so Testable's
     * own API is never intercepted.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if ($method === 'handle') {
            return $this->execute(fn (): mixed => $this->action->handle(...$arguments));
        }

        return $this->action->{$method}(...$arguments);
    }

    /**
     * Execute the action through a closure that receives the wrapped action:
     * the typed alternative to handle() for editors that do not resolve
     * generic mixins yet, with checked arguments and an inferred return type.
     * The closure runs inside the full instrumented flow (dry-run
     * transactions and the idempotency wrapper included), exactly like
     * handle().
     *
     * @template TReturn
     *
     * @param  Closure(TAction): TReturn  $callback
     * @return TReturn
     */
    public function run(Closure $callback): mixed
    {
        // The instrument wrappers erase the closure's return type, so the
        // result is claimed back as TReturn here - the same trust the
        // @mixin-typed handle() path implies, made explicit at this boundary.
        /** @var TReturn $result */
        $result = $this->execute(fn (): mixed => $callback($this->action));

        return $result;
    }

    /**
     * Run the given invocation attributed in Laravel's log Context, through
     * the dry-run transactions, the idempotency wrapper (each when
     * configured) and the instrumented execution flow.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function execute(Closure $invoke): mixed
    {
        return ActionContext::within($this->action, fn (): mixed => $this->executeInvocation($invoke));
    }

    /**
     * Run the given invocation through the dry-run transactions, the
     * idempotency wrapper (each when configured) and the instrumented
     * execution flow.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function executeInvocation(Closure $invoke): mixed
    {
        if (! $this->dryRun) {
            return $this->handleThrough($invoke);
        }

        $connections = array_map(
            static fn (?string $name) => DB::connection($name),
            $this->dryRunConnections
        );

        foreach ($connections as $connection) {
            $connection->beginTransaction();
        }

        try {
            return $this->handleThrough($invoke);
        } finally {
            // Unwind in reverse so nested begin/rollback pairs match.
            foreach (array_reverse($connections) as $connection) {
                $connection->rollBack();
            }
        }
    }

    /**
     * The execution flow behind handle() and run(): the idempotency wrapper
     * (when configured) around the instrumented run.
     *
     * @param  Closure(): mixed  $invoke
     */
    protected function handleThrough(Closure $invoke): mixed
    {
        if ($this->idempotency !== null) {
            // Wrap the whole instrumented run: a cache hit short-circuits
            // before any mock or proxy is bound, so there is nothing to
            // restore and nothing to report.
            return $this->idempotency->run(fn (): mixed => $this->handleInstrumented($invoke));
        }

        return $this->handleInstrumented($invoke);
    }

    /**
     * The instrumented execution flow behind handle() and run().
     *
     * @param  Closure(): mixed  $execute  The innermost invocation of the action
     */
    protected function handleInstrumented(Closure $execute): mixed
    {
        $this->handleOnly();
        $this->remakeActionForOnly();

        $this->intercept();

        // Wrap the execution innermost to outermost. The descriptors are
        // ordered profile, queries, logs, so profiling ends up innermost
        // (measuring only the action itself) with query and log recording
        // wrapped around it.
        foreach ($this->instruments as $instrument) {
            if (! $instrument->wrapMainAction) {
                continue;
            }

            $inner = $execute;

            $execute = function () use ($inner, $instrument): mixed {
                $listener = ($instrument->createListener)($this->action, $this->action);
                $result = $listener->listen($inner);
                ($instrument->addResults)($this, ($instrument->readResults)($listener));

                return $result;
            };
        }

        try {
            $result = $execute();
        } finally {
            $this->restoreContainer();
        }

        foreach ($this->instruments as $instrument) {
            $instrument->report();
        }

        foreach ($this->postRunChecks as $check) {
            $check();
        }

        return $result;
    }

    /**
     * @param  array<int, Query>  $queries
     */
    public function addQueries(array $queries): void
    {
        $this->instruments['queries']->collect($queries);
    }

    /**
     * @param  array<int, Entry>  $logs
     */
    public function addLogs(array $logs): void
    {
        $this->instruments['logs']->collect($logs);
    }

    public function addProfile(Profile $profile): void
    {
        $this->instruments['profile']->collect([$profile]);
    }

    /**
     * @param  array<int, EmittedEvent>  $events
     */
    public function addEvents(array $events): void
    {
        $this->instruments['events']->collect($events);
    }

    /**
     * Bind container proxies for every nested action registered across all
     * instrumentation features, so resolving one of them during the run is
     * recorded through that feature's listener.
     */
    protected function intercept(): void
    {
        foreach ($this->instruments as $instrument) {
            foreach ($instrument->actions as $actionClass) {
                $this->bindProxyWrapper($actionClass, function (Action $action) use ($actionClass, $instrument): object {
                    // Use the original action class (the resolved action might already be a proxy)
                    $proxyClass = $this->createProxyClass($actionClass);
                    $config = new ProxyConfiguration(
                        $instrument->createListener,
                        $instrument->addResults,
                        $instrument->readResults,
                    );

                    return new $proxyClass($this, $action, $config);
                });
            }
        }
    }

    /**
     * @param  class-string<Action>  $actionClass
     * @param  Closure(Action): object  $wrapper
     */
    protected function bindProxyWrapper(string $actionClass, Closure $wrapper): void
    {
        // Remember the original binding the first time we touch a class, so
        // the container can be restored after handle() completes
        if (! array_key_exists($actionClass, $this->replacedBindings)) {
            $binding = app()->getBindings()[$actionClass] ?? null;

            $this->replacedBindings[$actionClass] = $binding === null ? null : [
                'concrete' => $binding['concrete'] ?? null,
                'shared' => (bool) ($binding['shared'] ?? false),
            ];
        }

        // Capture the previous resolver if one exists (another feature may have already bound it)
        $previousResolver = null;

        if (app()->bound($actionClass)) {
            $concrete = app()->getBindings()[$actionClass]['concrete'] ?? null;

            if ($concrete instanceof Closure) {
                $previousResolver = $concrete;
            }
        }

        app()->bind($actionClass, function () use ($actionClass, $wrapper, $previousResolver): object {
            // If there's a previous resolver (another feature), use it to resolve
            // Otherwise, resolve the original action from the container
            if ($previousResolver) {
                $resolved = $previousResolver();
            } else {
                // Temporarily unbind to get the original action
                app()->offsetUnset($actionClass);
                $resolved = $actionClass::make();
                // Rebind for future resolutions
                $this->bindProxyWrapper($actionClass, $wrapper);
            }

            // Wrap whatever we resolved with our proxy. Anything that is not
            // an action (e.g. a bound mock) is passed through untouched.
            if ($resolved instanceof Action) {
                return $wrapper($resolved);
            }

            if (is_object($resolved)) {
                return $resolved;
            }

            throw new RuntimeException("Unable to resolve {$actionClass} from the container");
        });
    }

    /**
     * @return class-string
     */
    protected function createProxyClass(string $actionClass): string
    {
        // Create a dynamic proxy class that extends the action and uses the proxy trait
        $proxyClass = 'Proxy_'.md5($actionClass.spl_object_id($this));
        $fqcn = '\\'.ltrim($actionClass, '\\');

        if (! class_exists($actionClass)) {
            throw new InvalidArgumentException("Invalid class: $actionClass");
        }

        // Check if class already exists
        if (! class_exists($proxyClass)) {
            // Mirror the action's own return type so the override stays
            // compatible with typed handle() signatures
            $type = $this->handleReturnType($actionClass);
            $returnDeclaration = $type === null ? '' : ': '.$type;
            $delegate = $type instanceof \ReflectionNamedType && in_array($type->getName(), ['void', 'never'], true)
                ? '$this->proxyHandle(...$args);'
                : 'return $this->proxyHandle(...$args);';

            eval(<<<PHP
                final class $proxyClass extends $fqcn
                {
                    use \\Iak\\Action\\Testing\\Traits\\ProxyTrait;

                    public function handle(...\$args)$returnDeclaration
                    {
                        $delegate
                    }
                }
            PHP);
        }

        return $this->ensureClassExists($proxyClass);
    }

    /**
     * @param  class-string  $actionClass
     */
    protected function handleReturnType(string $actionClass): ?\ReflectionType
    {
        if (! method_exists($actionClass, 'handle')) {
            return null;
        }

        return (new \ReflectionMethod($actionClass, 'handle'))->getReturnType();
    }

    /**
     * Zero value matching the action's declared handle() return type, so
     * auto-mocked actions do not violate their own signatures when invoked
     *
     * @param  class-string<Action>  $actionClass
     */
    protected function defaultHandleReturnValue(string $actionClass): mixed
    {
        $type = $this->handleReturnType($actionClass);

        if (! $type instanceof \ReflectionNamedType || $type->allowsNull()) {
            return null;
        }

        return match ($type->getName()) {
            'string' => '',
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'array', 'iterable' => [],
            default => null,
        };
    }

    /**
     * Assert that a class exists, e.g. after being defined dynamically
     *
     * @return class-string
     */
    protected function ensureClassExists(string $class): string
    {
        if (! class_exists($class)) {
            throw new RuntimeException("Failed to create proxy class {$class}");
        }

        return $class;
    }

    protected function handleOnly(): void
    {
        if (empty($this->only)) {
            return;
        }

        $this->interceptingOnly = true;

        if ($this->onlyHookRegistered) {
            return;
        }

        $this->onlyHookRegistered = true;

        // The container offers no way to remove a beforeResolving hook, so the
        // hook holds only a weak reference and deactivates itself once the
        // testable run has completed (or the testable is garbage collected).
        // The closure must be static - non-static closures bind $this even
        // when they do not use it, which would defeat the weak reference.
        $reference = \WeakReference::create($this);

        app()->beforeResolving(static function (mixed $abstract) use ($reference): void {
            $testable = $reference->get();

            if (! $testable instanceof self || ! $testable->interceptingOnly) {
                return;
            }

            if (! is_string($abstract) || ! class_exists($abstract)) {
                return;
            }

            if (! is_subclass_of($abstract, Action::class)) {
                return;
            }

            if ($abstract === $testable->action::class) {
                return;
            }

            if (in_array($abstract, $testable->only, true)) {
                return;
            }

            // While the action under test is re-resolved, mock its
            // constructor-injected dependencies: no action instance is on the
            // call stack yet, so the backtrace inspection below cannot apply
            if ($testable->resolvingMainAction) {
                $abstract::fake()
                    ->shouldReceive('handle')
                    ->withAnyArgs()
                    ->andReturn($testable->defaultHandleReturnValue($abstract));

                return;
            }

            foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT) as $frame) {
                if (! isset($frame['object']) || $frame['object'] === $testable) {
                    continue;
                }

                if (! $frame['object'] instanceof ($testable->action::class)) {
                    continue;
                }

                $abstract::fake()
                    ->shouldReceive('handle')
                    ->withAnyArgs()
                    ->andReturn($testable->defaultHandleReturnValue($abstract));

                break;
            }
        });
    }

    /**
     * Re-resolve the action under test so that constructor-injected child
     * actions pass through the only() hook. The action given to test() was
     * resolved before the hook existed, so its dependencies are real.
     */
    protected function remakeActionForOnly(): void
    {
        if (empty($this->only)) {
            return;
        }

        $this->resolvingMainAction = true;

        try {
            $this->action = $this->action::class::make();
        } finally {
            $this->resolvingMainAction = false;
        }
    }

    /**
     * Undo the container manipulation done for this run: restore the
     * bindings replaced by proxies and deactivate the only() hook. Mocks
     * already bound for mocked-away actions stay in place.
     */
    protected function restoreContainer(): void
    {
        $this->interceptingOnly = false;

        foreach ($this->replacedBindings as $class => $binding) {
            app()->offsetUnset($class);

            $concrete = $binding['concrete'] ?? null;

            if ($concrete instanceof Closure || is_string($concrete)) {
                app()->bind($class, $concrete, $binding['shared'] ?? false);
            }
        }

        $this->replacedBindings = [];
    }

    /**
     * Validate that a `without()`/`only()` entry is an action class name
     *
     * @return class-string<Action>
     */
    protected function resolveActionClass(mixed $class): string
    {
        if (! is_string($class) || ! is_subclass_of($class, Action::class)) {
            $name = is_string($class) ? $class : get_debug_type($class);

            throw new InvalidArgumentException("The class or alias {$name} is not bound to the container");
        }

        return $class;
    }

    /**
     * Validate that an action class can be proxied for profiling or recording
     *
     * @return class-string<Action>
     */
    protected function resolveProxyableActionClass(mixed $class): string
    {
        $class = $this->resolveActionClass($class);

        if ((new \ReflectionClass($class))->isFinal()) {
            throw new InvalidArgumentException(
                "Cannot profile or record {$class}: final classes cannot be proxied. Remove the final keyword to instrument this action."
            );
        }

        return $class;
    }

    /**
     * Split a `without()` entry into the target class or mock and its
     * optional mocked return value
     *
     * @return array{mixed, mixed}
     */
    protected function getClassAndReturnValue(mixed $class, string|int $key): array
    {
        if (is_string($key)) {
            return [$key, $class];
        }

        if (is_array($class)) {
            return [array_key_first($class), array_values($class)[0]];
        }

        return [$class, null];
    }
}
