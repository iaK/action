<?php

use Iak\Action\Testing\Results\Memory;

describe('Memory', function () {
    it('can create memory record', function () {
        $memory = new Memory('test-point', 1024, 1000.5);

        expect($memory->name)->toBe('test-point');
        expect($memory->memory)->toBe(1024);
        expect($memory->timestamp)->toBe(1000.5);
    });

    it('can format memory without unit', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->formattedMemory())->toBe('1 KB');
    });

    it('can format memory in bytes', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->formattedMemory('B'))->toBe(1024);
        expect($memory->formattedMemory('BYTES'))->toBe(1024);
    });

    it('can format memory in kilobytes', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->formattedMemory('KB'))->toBe(1);
        expect($memory->formattedMemory('KILOBYTES'))->toBe(1);
    });

    it('can format memory in megabytes', function () {
        $memory = new Memory('test', 1024 * 1024, 1000.0);

        expect($memory->formattedMemory('MB'))->toBe(1);
        expect($memory->formattedMemory('MEGABYTES'))->toBe(1);
    });

    it('can format memory in gigabytes', function () {
        $memory = new Memory('test', 1024 * 1024 * 1024, 1000.0);

        expect($memory->formattedMemory('GB'))->toBe(1);
        expect($memory->formattedMemory('GIGABYTES'))->toBe(1);
    });

    it('can format memory in terabytes', function () {
        $memory = new Memory('test', 1024 * 1024 * 1024 * 1024, 1000.0);

        expect($memory->formattedMemory('TB'))->toBe(1);
        expect($memory->formattedMemory('TERABYTES'))->toBe(1);
    });

    it('can format small memory values', function () {
        $memory = new Memory('test', 512, 1000.0);

        expect($memory->formattedMemory('B'))->toBe(512);
        expect($memory->formattedMemory('KB'))->toBe(0); // Truncated to int due to return type
        expect($memory->formattedMemory())->toBe('512 B');
    });

    it('can format large memory values', function () {
        $memory = new Memory('test', 2048 * 1024 * 1024, 1000.0);

        expect($memory->formattedMemory('MB'))->toBe(2048);
        expect($memory->formattedMemory('GB'))->toBe(2);
        expect($memory->formattedMemory())->toBe('2 GB');
    });

    it('handles negative memory values', function () {
        $memory = new Memory('test', -1024, 1000.0);

        expect($memory->formattedMemory('B'))->toBe(-1024);
        expect($memory->formattedMemory('KB'))->toBe(-1);
        expect($memory->formattedMemory())->toBe('-1 KB');
    });

    it('handles zero memory', function () {
        $memory = new Memory('test', 0, 1000.0);

        expect($memory->formattedMemory('B'))->toBe(0);
        expect($memory->formattedMemory('KB'))->toBe(0);
        expect($memory->formattedMemory())->toBe('0 B');
    });

    it('throws exception for invalid unit', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect(fn () => $memory->formattedMemory('INVALID'))->toThrow(InvalidArgumentException::class);
    });

    it('supports case insensitive unit names', function () {
        $memory = new Memory('test', 1024, 1000.0);

        expect($memory->formattedMemory('kb'))->toBe(1);
        expect($memory->formattedMemory('Mb'))->toBe(0);
        expect($memory->formattedMemory('bytes'))->toBe(1024);
    });

    it('rounds memory values correctly', function () {
        $memory = new Memory('test', 1536, 1000.0); // 1.5 KB

        expect($memory->formattedMemory('KB'))->toBe(1); // Truncated to int due to return type
        expect($memory->formattedMemory('MB'))->toBe(0);
    });

    it('can format fractional memory values', function () {
        $memory = new Memory('test', 512 * 1024 * 1024, 1000.0); // 0.5 GB

        expect($memory->formattedMemory('GB'))->toBe(0); // Truncated to int due to return type
        expect($memory->formattedMemory('MB'))->toBe(512);
    });
});

