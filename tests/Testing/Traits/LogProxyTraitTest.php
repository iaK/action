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
        $testable = $this->createMock(Testable::class);
        $testable->logListener = null;
        
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

    it('creates log listener if not exists', function () {
        $testable = $this->createMock(Testable::class);
        $testable->logListener = null;
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $proxy->handle();
        
        expect($testable->logListener)->toBeInstanceOf(LogListener::class);
    });

    it('uses existing log listener', function () {
        $existingListener = $this->createMock(LogListener::class);
        $existingListener->expects($this->once())
                        ->method('listen')
                        ->willReturnCallback(function ($callback) {
                            return $callback();
                        });
        
        $testable = $this->createMock(Testable::class);
        $testable->logListener = $existingListener;
        
        $action = $this->createMock(ClosureAction::class);
        $action->expects($this->once())
               ->method('handle')
               ->willReturn('test result');
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $result = $proxy->handle();
        
        expect($result)->toBe('test result');
        expect($testable->logListener)->toBe($existingListener);
    });

    it('properly delegates to wrapped action', function () {
        $testable = $this->createMock(Testable::class);
        $testable->logListener = null;
        
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
        $testable = $this->createMock(Testable::class);
        $testable->logListener = null;
        
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        // Before calling handle, logListener should be null
        expect($testable->logListener)->toBeNull();
        
        $proxy->handle();
        
        // After calling handle, logListener should be created
        expect($testable->logListener)->toBeInstanceOf(LogListener::class);
    });

    it('maintains log listener reference across multiple calls', function () {
        $testable = $this->createMock(Testable::class);
        $testable->logListener = null;
        
        $action = new ClosureAction();
        
        $proxy = new class($testable, $action) extends ClosureAction {
            use LogProxyTrait;
        };
        
        $proxy->handle();
        $firstListener = $testable->logListener;
        
        $proxy->handle();
        $secondListener = $testable->logListener;
        
        // Should be the same instance
        expect($firstListener)->toBe($secondListener);
    });
});
