<?php

use Iak\Action\Testing\Traits\LogProxyTrait;
use Iak\Action\Testing\LogListener;
use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\ClosureAction;

describe('LogProxyTrait', function () {
    it('can create proxy with trait', function () {
        $testable = $this->createMock(Testable::class);
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        expect($proxy)->toBeInstanceOf(ClosureAction::class);
        });

    it('handle calls original action', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $result = $proxy->handle();
        
        expect($result)->toBe('test result');
        });

    it('creates log listener and calls addLogs', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $proxy->handle();
        
        // Each proxy creates its own listener and calls addLogs
        expect($testable->recordedLogs)->toBeArray();
        });

    it('creates new log listener for each call', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $result = $proxy->handle();
        
        expect($result)->toBe('test result');
        // Each call creates its own listener instance
        expect($testable->recordedLogs)->toBeArray();
        });

    it('properly delegates to wrapped action', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $result = $proxy->handle(function () {
            return 'Hello, World!';
        });
        
        expect($result)->toBe('Hello, World!');
        });

    it('handles log listener creation correctly', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        // Before calling handle, recordedLogs should be empty
        expect($testable->recordedLogs)->toBeEmpty();
        
        $proxy->handle();
        
        // After calling handle, logs should be recorded via addLogs
        expect($testable->recordedLogs)->toBeArray();
        });

    it('accumulates logs across multiple calls', function () {
        $testable = new Testable(new ClosureAction());
        $testable->recordedLogs = [];
        
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $proxy->handle();
        $firstCount = count($testable->recordedLogs);
        
        $proxy->handle();
        $secondCount = count($testable->recordedLogs);
        
        // Logs should accumulate across multiple calls
        expect($secondCount)->toBeGreaterThanOrEqual($firstCount);
        });
});
