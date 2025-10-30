<?php

use Iak\Action\Testing\Results\Measurement;
use Carbon\CarbonInterval;

it('can create measurement', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    expect($measurement->class)->toBe('TestClass');
    expect($measurement->start)->toBe($start);
    expect($measurement->end)->toBe($end);
});

it('can calculate duration', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $duration = $measurement->duration();
    
    expect($duration)->toBeInstanceOf(CarbonInterval::class);
    expect($duration->totalMilliseconds)->toBe(1500.0);
});

it('has string representation', function () {
    $start = 1000.0;
    $end = 1001.5;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $expected = 'TestClass took 1500ms';
    expect((string) $measurement)->toBe($expected);
});

it('calculates duration with zero time', function () {
    $start = 1000.0;
    $end = 1000.0;
    $measurement = new Measurement('TestClass', $start, $end);
    
    $duration = $measurement->duration();
    
    expect($duration->totalMilliseconds)->toBe(0.0);
});

it('can create measurement with memory tracking', function () {
    $start = 1000.0;
    $end = 1001.5;
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $peakMemory = 3 * 1024 * 1024; // 3MB
    
    $measurement = new Measurement('TestClass', $start, $end, $startMemory, $endMemory, $peakMemory);
    
    expect($measurement->class)->toBe('TestClass');
    expect($measurement->start)->toBe($start);
    expect($measurement->end)->toBe($end);
    expect($measurement->startMemory)->toBe($startMemory);
    expect($measurement->endMemory)->toBe($endMemory);
    expect($measurement->peakMemory)->toBe($peakMemory);
});

it('can calculate memory used', function () {
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);
    
    expect($measurement->memoryUsed('B'))->toBe(1024 * 1024); // 1MB in bytes
    expect($measurement->memoryUsed())->toBe('1 MB'); // formatted
});

it('can format memory usage through public methods', function () {
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 0, 0, 0);
    
    expect($measurement->startMemory())->toBe('0 B');
    
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 0, 0);
    expect($measurement->startMemory())->toBe('1 KB');
    
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024 * 1024, 0, 0);
    expect($measurement->startMemory())->toBe('1 MB');
    
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024 * 1024 * 1024, 0, 0);
    expect($measurement->startMemory())->toBe('1 GB');
});

it('can format memory used', function () {
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);
    
    expect($measurement->memoryUsed())->toBe('1 MB');
});

it('can format peak memory', function () {
    $peakMemory = 3 * 1024 * 1024; // 3MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 0, 0, $peakMemory);
    
    expect($measurement->peakMemory())->toBe('3 MB');
});

it('can format start and end memory', function () {
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);
    
    expect($measurement->startMemory())->toBe('1 MB');
    expect($measurement->endMemory())->toBe('2 MB');
});

it('includes memory info in string representation when memory is tracked', function () {
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $peakMemory = 3 * 1024 * 1024; // 3MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.5, $startMemory, $endMemory, $peakMemory);
    
    $expected = 'TestClass took 1500ms (memory: 1 MB, peak: 3 MB)';
    expect((string) $measurement)->toBe($expected);
});

it('does not include memory info in string representation when memory is not tracked', function () {
    $measurement = new Measurement('TestClass', 1000.0, 1001.5);
    
    $expected = 'TestClass took 1500ms';
    expect((string) $measurement)->toBe($expected);
});

it('handles negative memory usage gracefully', function () {
    $startMemory = 2 * 1024 * 1024; // 2MB
    $endMemory = 1024 * 1024; // 1MB (less than start)
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, 0);
    
    expect($measurement->memoryUsed('B'))->toBe(-1024 * 1024); // -1MB in bytes
    expect($measurement->memoryUsed())->toBe('-1 MB'); // formatted
});

it('can get memory values in specific units', function () {
    $startMemory = 1024 * 1024; // 1MB
    $endMemory = 2 * 1024 * 1024; // 2MB
    $peakMemory = 3 * 1024 * 1024; // 3MB
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, $startMemory, $endMemory, $peakMemory);
    
    // Test different units
    expect($measurement->memoryUsed('B'))->toBe(1024 * 1024); // 1MB in bytes
    expect($measurement->memoryUsed('KB'))->toBe(1024); // 1MB in KB
    expect($measurement->memoryUsed('MB'))->toBe(1); // 1MB in MB
    expect($measurement->memoryUsed('GB'))->toBe(0); // 1MB in GB (rounded)
    
    expect($measurement->startMemory('B'))->toBe(1024 * 1024);
    expect($measurement->startMemory('KB'))->toBe(1024);
    expect($measurement->startMemory('MB'))->toBe(1);
    
    expect($measurement->endMemory('B'))->toBe(2 * 1024 * 1024);
    expect($measurement->endMemory('KB'))->toBe(2048);
    expect($measurement->endMemory('MB'))->toBe(2);
    
    expect($measurement->peakMemory('B'))->toBe(3 * 1024 * 1024);
    expect($measurement->peakMemory('KB'))->toBe(3072);
    expect($measurement->peakMemory('MB'))->toBe(3);
});

it('throws exception for invalid unit', function () {
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 2048, 0);
    
    expect(fn() => $measurement->memoryUsed('INVALID'))->toThrow(InvalidArgumentException::class);
    expect(fn() => $measurement->startMemory('INVALID'))->toThrow(InvalidArgumentException::class);
    expect(fn() => $measurement->endMemory('INVALID'))->toThrow(InvalidArgumentException::class);
    expect(fn() => $measurement->peakMemory('INVALID'))->toThrow(InvalidArgumentException::class);
});

it('supports different unit formats', function () {
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 2048, 0);
    
    // Test both short and long forms
    expect($measurement->memoryUsed('B'))->toBe(1024);
    expect($measurement->memoryUsed('BYTES'))->toBe(1024);
    expect($measurement->memoryUsed('KB'))->toBe(1);
    expect($measurement->memoryUsed('KILOBYTES'))->toBe(1);
    expect($measurement->memoryUsed('MB'))->toBe(0);
    expect($measurement->memoryUsed('MEGABYTES'))->toBe(0);
});

it('can create measurement with memory records', function () {
    $memoryRecords = [
        ['name' => 'start', 'memory' => 1024, 'timestamp' => 1000.1],
        ['name' => 'middle', 'memory' => 2048, 'timestamp' => 1000.5],
        ['name' => 'end', 'memory' => 3072, 'timestamp' => 1000.9]
    ];
    
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 2048, 3072, $memoryRecords);
    
    expect($measurement->memoryRecords)->toHaveCount(3);
    expect($measurement->memoryRecords[0]['name'])->toBe('start');
    expect($measurement->memoryRecords[0]['memory'])->toBe(1024);
    expect($measurement->memoryRecords[1]['name'])->toBe('middle');
    expect($measurement->memoryRecords[1]['memory'])->toBe(2048);
    expect($measurement->memoryRecords[2]['name'])->toBe('end');
    expect($measurement->memoryRecords[2]['memory'])->toBe(3072);
});

it('can get formatted memory records', function () {
    $memoryRecords = [
        ['name' => 'start', 'memory' => 1024, 'timestamp' => 1000.1],
        ['name' => 'middle', 'memory' => 2048, 'timestamp' => 1000.5],
        ['name' => 'end', 'memory' => 3072, 'timestamp' => 1000.9]
    ];
    
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 2048, 3072, $memoryRecords);
    $records = $measurement->records();
    
    expect($records)->toHaveCount(3);
    
    // Check first record
    expect($records[0]['name'])->toBe('start');
    expect($records[0]['memory'])->toBe(1024);
    expect($records[0]['memory_formatted'])->toBe('1 KB');
    expect($records[0]['timestamp'])->toBe(1000.1);
    expect(abs($records[0]['relative_time'] - 0.1))->toBeLessThan(0.0001);
    
    // Check second record
    expect($records[1]['name'])->toBe('middle');
    expect($records[1]['memory'])->toBe(2048);
    expect($records[1]['memory_formatted'])->toBe('2 KB');
    expect($records[1]['timestamp'])->toBe(1000.5);
    expect(abs($records[1]['relative_time'] - 0.5))->toBeLessThan(0.0001);
    
    // Check third record
    expect($records[2]['name'])->toBe('end');
    expect($records[2]['memory'])->toBe(3072);
    expect($records[2]['memory_formatted'])->toBe('3 KB');
    expect($records[2]['timestamp'])->toBe(1000.9);
    expect(abs($records[2]['relative_time'] - 0.9))->toBeLessThan(0.0001);
});

it('returns empty array when no memory records', function () {
    $measurement = new Measurement('TestClass', 1000.0, 1001.0, 1024, 2048, 3072);
    
    expect($measurement->records())->toBe([]);
    expect($measurement->memoryRecords)->toBe([]);
});
