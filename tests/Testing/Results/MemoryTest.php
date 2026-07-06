<?php

use Iak\Action\Testing\Results\Memory;
use Iak\Action\Testing\Results\MemorySize;

describe('Memory', function () {
    it('can create memory record', function () {
        $memory = new Memory('test-point', 1024, 1000.5);

        expect($memory->name)->toBe('test-point');
        expect($memory->memory)->toBe(1024);
        expect($memory->timestamp)->toBe(1000.5);
    });

    it('exposes its size as a memory size value object', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->size())->toBeInstanceOf(MemorySize::class);
        expect($memory->size()->bytes())->toBe(1024);
    });

    it('can format its size', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->size()->format())->toBe('1 KB');
    });

    it('can convert its size to specific units', function () {
        $memory = new Memory('test', 1536, 1000.0);

        expect($memory->size()->in('B'))->toBe(1536.0);
        expect($memory->size()->in('KB'))->toBe(1.5);
        expect($memory->size()->in('MB'))->toBe(0.0);
    });

    it('handles negative memory values', function () {
        $memory = new Memory('test', -1024, 1000.0);

        expect($memory->size()->bytes())->toBe(-1024);
        expect($memory->size()->in('KB'))->toBe(-1.0);
        expect($memory->size()->format())->toBe('-1 KB');
    });

    it('handles zero memory', function () {
        $memory = new Memory('test', 0, 1000.0);

        expect($memory->size()->bytes())->toBe(0);
        expect($memory->size()->format())->toBe('0 B');
    });

    it('throws exception for invalid unit', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect(fn () => $memory->size()->in('INVALID'))->toThrow(InvalidArgumentException::class);
    });
});
