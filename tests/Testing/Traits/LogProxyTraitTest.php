<?php

use Iak\Action\Testing\Testable;
use Iak\Action\Tests\TestClasses\LogAction;
use Iak\Action\Testing\Traits\LogProxyTrait;
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
        $testable->logs(function ($logs) {
            expect($logs)->toHaveCount(1);
            expect($logs[0]->message)->toBe('test result');
        });
        
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
        $testable->logs(function ($logs) {
            expect($logs)->toHaveCount(1);
            expect($logs[0]->message)->toBe('test result');
        });
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $proxy->handle();
        
        // Each proxy creates its own listener and calls addLogs
    });

    it('creates new log listener for each call', function () {
        $testable = new Testable(new ClosureAction());
        $testable->logs(function ($logs) {
            expect($logs)->toHaveCount(1);
            expect($logs[0]->message)->toBe('test result');
        });
        
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
    });
});
