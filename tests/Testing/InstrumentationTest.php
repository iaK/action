<?php

use Iak\Action\Testing\LogListener;
use Iak\Action\Testing\QueryListener;
use Iak\Action\Testing\Results\Profile;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;
use Iak\Action\Tests\TestClasses\OtherClosureAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A Testable subclass observing its own public add hooks, as an integrator
 * extending the collection machinery would.
 *
 * @extends Testable<ClosureAction>
 */
function testableObservingHooks(): Testable
{
    return new class(ClosureAction::make()) extends Testable
    {
        /** @var array<string, int> */
        public array $hookCounts = [];

        public function addProfile(Profile $profile): void
        {
            $this->hookCounts['profile'] = ($this->hookCounts['profile'] ?? 0) + 1;
            parent::addProfile($profile);
        }

        public function addQueries(array $queries): void
        {
            $this->hookCounts['queries'] = ($this->hookCounts['queries'] ?? 0) + count($queries);
            parent::addQueries($queries);
        }

        public function addLogs(array $logs): void
        {
            $this->hookCounts['logs'] = ($this->hookCounts['logs'] ?? 0) + count($logs);
            parent::addLogs($logs);
        }

        public function addEvents(array $events): void
        {
            $this->hookCounts['events'] = ($this->hookCounts['events'] ?? 0) + count($events);
            parent::addEvents($events);
        }
    };
}

describe('Instrumentation Collection Funnel', function () {
    it('routes results collected for the action under test through the overridable add hooks', function () {
        $testable = testableObservingHooks();

        $testable
            ->profile(function (Collection $profiles) {
                expect($profiles)->toHaveCount(1);
            })
            ->queries(function (Collection $queries) {
                expect($queries)->toHaveCount(1);
            })
            ->logs(function (Collection $logs) {
                expect($logs)->toHaveCount(1);
            })
            ->events(function (Collection $events) {
                expect($events)->toHaveCount(1);
            })
            ->handle(function (ClosureAction $action) {
                DB::statement('SELECT 1');
                Log::info('funnel');
                $action->event('test.event.a', 'funneled');
            });

        expect($testable->hookCounts)->toEqual(['profile' => 1, 'queries' => 1, 'logs' => 1, 'events' => 1]);
    });

    it('routes results collected through nested-action proxies through the overridable add hooks', function () {
        $testable = testableObservingHooks();

        $testable
            ->queries(OtherClosureAction::class, function (Collection $queries) {
                expect($queries)->toHaveCount(1);
            })
            ->handle(function () {
                OtherClosureAction::make()->handle(function () {
                    DB::statement('SELECT 1');
                });
            });

        expect($testable->hookCounts)->toEqual(['queries' => 1]);
    });

    it('fails loudly when a listener is mispaired with an instrumentation descriptor', function () {
        $testable = ClosureAction::test();

        $property = new ReflectionProperty(Testable::class, 'instruments');
        $property->setAccessible(true);
        $instruments = $property->getValue($testable);

        expect(fn () => ($instruments['profile']->readResults)(new QueryListener(ClosureAction::class)))
            ->toThrow(LogicException::class, QueryListener::class);
        expect(fn () => ($instruments['queries']->readResults)(new LogListener(ClosureAction::class)))
            ->toThrow(LogicException::class, LogListener::class);
        expect(fn () => ($instruments['logs']->readResults)(new QueryListener(ClosureAction::class)))
            ->toThrow(LogicException::class, QueryListener::class);
        expect(fn () => ($instruments['events']->readResults)(new QueryListener(ClosureAction::class)))
            ->toThrow(LogicException::class, QueryListener::class);
    });
});
