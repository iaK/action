<?php

use Carbon\CarbonInterval;
use Iak\Action\Testing\Results\Memory;
use Iak\Action\Testing\Results\Profile;

describe('Profile', function () {
    it('can create profile', function () {
        $start = 1000.0;
        $end = 1001.5;
        $profile = new Profile('TestClass', $start, $end);

        expect($profile->class)->toBe('TestClass');
        expect($profile->start)->toBe($start);
        expect($profile->end)->toBe($end);
    });

    it('can calculate duration', function () {
        $start = 1000.0;
        $end = 1001.5;
        $profile = new Profile('TestClass', $start, $end);

        $duration = $profile->duration();

        expect($duration)->toBeInstanceOf(CarbonInterval::class);
        expect($duration->totalMilliseconds)->toBe(1500.0);
    });

    it('has string representation', function () {
        $start = 1000.0;
        $end = 1001.5;
        $profile = new Profile('TestClass', $start, $end);

        $expected = 'TestClass took 1500ms';
        expect((string) $profile)->toBe($expected);
    });

    it('calculates duration with zero time', function () {
        $start = 1000.0;
        $end = 1000.0;
        $profile = new Profile('TestClass', $start, $end);

        $duration = $profile->duration();

        expect($duration->totalMilliseconds)->toBe(0.0);
    });

    it('can create profile with memory tracking', function () {
        $start = 1000.0;
        $end = 1001.5;
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $peakMemory = 3 * 1024 * 1024; // 3MB

        $profile = new Profile('TestClass', $start, $end, $startMemory, $endMemory, $peakMemory);

        expect($profile->class)->toBe('TestClass');
        expect($profile->start)->toBe($start);
        expect($profile->end)->toBe($end);
        expect($profile->startMemory)->toBe($startMemory);
        expect($profile->endMemory)->toBe($endMemory);
        expect($profile->peakMemory)->toBe($peakMemory);
    });

    it('can calculate memory used', function () {
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $profile = new Profile('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);

        expect($profile->memoryUsed('B'))->toBe(1024 * 1024); // 1MB in bytes
        expect($profile->memoryUsed())->toBe('1 MB'); // formatted
    });

    it('can format memory usage through public methods', function () {
        $profile = new Profile('TestClass', 1000.0, 1001.0, 0, 0, 0);

        expect($profile->startMemory())->toBe('0 B');

        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 0, 0);
        expect($profile->startMemory())->toBe('1 KB');

        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024 * 1024, 0, 0);
        expect($profile->startMemory())->toBe('1 MB');

        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024 * 1024 * 1024, 0, 0);
        expect($profile->startMemory())->toBe('1 GB');
    });

    it('can format memory used', function () {
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $profile = new Profile('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);

        expect($profile->memoryUsed())->toBe('1 MB');
    });

    it('can format peak memory', function () {
        $peakMemory = 3 * 1024 * 1024; // 3MB
        $profile = new Profile('TestClass', 1000.0, 1001.0, 0, 0, $peakMemory);

        expect($profile->peakMemory())->toBe('3 MB');
    });

    it('can format start and end memory', function () {
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $profile = new Profile('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);

        expect($profile->startMemory())->toBe('1 MB');
        expect($profile->endMemory())->toBe('2 MB');
    });

    it('includes memory info in string representation when memory is tracked', function () {
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $peakMemory = 3 * 1024 * 1024; // 3MB
        $profile = new Profile('TestClass', 1000.0, 1001.5, $startMemory, $endMemory, $peakMemory);

        $expected = 'TestClass took 1500ms (memory: 1 MB, peak: 3 MB)';
        expect((string) $profile)->toBe($expected);
    });

    it('does not include memory info in string representation when memory is not tracked', function () {
        $profile = new Profile('TestClass', 1000.0, 1001.5);

        $expected = 'TestClass took 1500ms';
        expect((string) $profile)->toBe($expected);
    });

    it('handles negative memory usage gracefully', function () {
        $startMemory = 2 * 1024 * 1024; // 2MB
        $endMemory = 1024 * 1024; // 1MB (less than start)
        $profile = new Profile('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);

        expect($profile->memoryUsed('B'))->toBe(-1024 * 1024); // -1MB in bytes
        expect($profile->memoryUsed())->toBe('-1 MB'); // formatted
    });

    it('can get memory values in specific units', function () {
        $startMemory = 1024 * 1024; // 1MB
        $endMemory = 2 * 1024 * 1024; // 2MB
        $peakMemory = 3 * 1024 * 1024; // 3MB
        $profile = new Profile('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, $peakMemory);

        // Test different units
        expect($profile->memoryUsed('B'))->toBe(1024 * 1024); // 1MB in bytes
        expect($profile->memoryUsed('KB'))->toBe(1024); // 1MB in KB
        expect($profile->memoryUsed('MB'))->toBe(1); // 1MB in MB
        expect($profile->memoryUsed('GB'))->toBe(0); // 1MB in GB (rounded)

        expect($profile->startMemory('B'))->toBe(1024 * 1024);
        expect($profile->startMemory('KB'))->toBe(1024);
        expect($profile->startMemory('MB'))->toBe(1);

        expect($profile->endMemory('B'))->toBe(2 * 1024 * 1024);
        expect($profile->endMemory('KB'))->toBe(2048);
        expect($profile->endMemory('MB'))->toBe(2);

        expect($profile->peakMemory('B'))->toBe(3 * 1024 * 1024);
        expect($profile->peakMemory('KB'))->toBe(3072);
        expect($profile->peakMemory('MB'))->toBe(3);
    });

    it('throws exception for invalid unit', function () {
        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 2048, 0);

        expect(fn () => $profile->memoryUsed('INVALID'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $profile->startMemory('INVALID'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $profile->endMemory('INVALID'))->toThrow(InvalidArgumentException::class);
        expect(fn () => $profile->peakMemory('INVALID'))->toThrow(InvalidArgumentException::class);
    });

    it('supports different unit formats', function () {
        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 2048, 0);

        // Test both short and long forms
        expect($profile->memoryUsed('B'))->toBe(1024);
        expect($profile->memoryUsed('BYTES'))->toBe(1024);
        expect($profile->memoryUsed('KB'))->toBe(1);
        expect($profile->memoryUsed('KILOBYTES'))->toBe(1);
        expect($profile->memoryUsed('MB'))->toBe(0);
        expect($profile->memoryUsed('MEGABYTES'))->toBe(0);
    });

    it('can create profile with memory records', function () {
        $memoryRecords = [
            new Memory('start', 1024, 1000.1),
            new Memory('middle', 2048, 1000.5),
            new Memory('end', 3072, 1000.9),
        ];

        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 2048, 3072, $memoryRecords);

        expect($profile->memoryRecords)->toHaveCount(3);
        expect($profile->memoryRecords[0]->name)->toBe('start');
        expect($profile->memoryRecords[0]->memory)->toBe(1024);
        expect($profile->memoryRecords[1]->name)->toBe('middle');
        expect($profile->memoryRecords[1]->memory)->toBe(2048);
        expect($profile->memoryRecords[2]->name)->toBe('end');
        expect($profile->memoryRecords[2]->memory)->toBe(3072);
    });

    it('can get formatted memory records', function () {
        $memoryRecords = [
            new Memory('start', 1024, 1000.1),
            new Memory('middle', 2048, 1000.5),
            new Memory('end', 3072, 1000.9),
        ];

        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 2048, 3072, $memoryRecords);
        $records = $profile->records();

        expect($records)->toHaveCount(3);

        // Check first record
        expect($records[0]->name)->toBe('start');
        expect($records[0]->memory)->toBe(1024);
        expect($records[0]->formattedMemory())->toBe('1 KB');
        expect($records[0]->timestamp)->toBe(1000.1);

        // Check second record
        expect($records[1]->name)->toBe('middle');
        expect($records[1]->memory)->toBe(2048);
        expect($records[1]->formattedMemory())->toBe('2 KB');
        expect($records[1]->timestamp)->toBe(1000.5);

        // Check third record
        expect($records[2]->name)->toBe('end');
        expect($records[2]->memory)->toBe(3072);
        expect($records[2]->formattedMemory())->toBe('3 KB');
        expect($records[2]->timestamp)->toBe(1000.9);
    });

    it('returns empty array when no memory records', function () {
        $profile = new Profile('TestClass', 1000.0, 1001.0, 1024, 2048, 3072);

        expect($profile->records())->toBe([]);
        expect($profile->memoryRecords)->toBe([]);
    });
});
