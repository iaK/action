<?php

use Iak\Action\Testing\Results\Memory;
use Iak\Action\Testing\Results\MemorySize;
use Iak\Action\Testing\Results\Profile;

describe('MemorySize', function () {
    it('exposes raw bytes', function () {
        $size = new MemorySize(2048);

        expect($size->bytes())->toBe(2048);
    });

    it('converts to units as floats without losing precision', function () {
        expect((new MemorySize(1536))->in('KB'))->toBe(1.5);
        expect((new MemorySize(512))->in('KB'))->toBe(0.5);
        expect((new MemorySize(1572864))->in('MB'))->toBe(1.5);
        expect((new MemorySize(2 * 1024 * 1024 * 1024))->in('GB'))->toBe(2.0);
        expect((new MemorySize(1024 ** 4))->in('TB'))->toBe(1.0);
    });

    it('converts bytes to a float', function () {
        expect((new MemorySize(2048))->in('B'))->toBe(2048.0);
    });

    it('rounds conversions to two decimals', function () {
        expect((new MemorySize(1000))->in('KB'))->toBe(0.98);
    });

    it('handles negative values', function () {
        expect((new MemorySize(-1536))->in('KB'))->toBe(-1.5);
        expect((new MemorySize(-1536))->format())->toBe('-1.5 KB');
    });

    it('supports case insensitive and long unit names', function () {
        $size = new MemorySize(1536);

        expect($size->in('kb'))->toBe(1.5);
        expect($size->in('Kb'))->toBe(1.5);
        expect($size->in('KILOBYTES'))->toBe(1.5);
        expect($size->in('bytes'))->toBe(1536.0);
        expect($size->in('megabytes'))->toBe(0.0);
    });

    it('throws on invalid units', function () {
        expect(fn () => (new MemorySize(1024))->in('INVALID'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('formats to a human readable string', function () {
        expect((new MemorySize(0))->format())->toBe('0 B');
        expect((new MemorySize(512))->format())->toBe('512 B');
        expect((new MemorySize(1024))->format())->toBe('1 KB');
        expect((new MemorySize(1536))->format())->toBe('1.5 KB');
        expect((new MemorySize(2 * 1024 * 1024 * 1024))->format())->toBe('2 GB');
    });

    it('casts to the formatted string', function () {
        expect((string) new MemorySize(1536))->toBe('1.5 KB');
    });
});

describe('Profile memory sizes', function () {
    it('returns memory size value objects', function () {
        $profile = new Profile(
            class: 'App\Actions\TestAction',
            start: 0.0,
            end: 1.0,
            startMemory: 1024,
            endMemory: 3072,
            peakMemory: 4096,
        );

        expect($profile->memoryUsed())->toBeInstanceOf(MemorySize::class);
        expect($profile->memoryUsed()->bytes())->toBe(2048);
        expect($profile->startMemory()->bytes())->toBe(1024);
        expect($profile->endMemory()->bytes())->toBe(3072);
        expect($profile->peakMemory()->bytes())->toBe(4096);
    });

    it('does not truncate fractional unit conversions', function () {
        $profile = new Profile(
            class: 'App\Actions\TestAction',
            start: 0.0,
            end: 1.0,
            startMemory: 0,
            endMemory: 512,
            peakMemory: 512,
        );

        expect($profile->memoryUsed()->in('KB'))->toBe(0.5);
    });
});

describe('Memory record size', function () {
    it('returns a memory size value object', function () {
        $memory = new Memory(name: 'checkpoint', memory: 1536, timestamp: 1.0);

        expect($memory->size())->toBeInstanceOf(MemorySize::class);
        expect($memory->size()->bytes())->toBe(1536);
        expect($memory->size()->in('KB'))->toBe(1.5);
        expect($memory->size()->format())->toBe('1.5 KB');
    });
});
