<?php

use Iak\Action\Testing\Results\Entry;
use Carbon\Carbon;

describe('Entry', function () {
    it('can create entry', function () {
        $timestamp = Carbon::now();
        $entry = new Entry('INFO', 'Test message', ['key' => 'value'], $timestamp, 'test-channel');
        
        expect($entry->level)->toBe('INFO');
        expect($entry->message)->toBe('Test message');
        expect($entry->context)->toBe(['key' => 'value']);
        expect($entry->timestamp)->toBe($timestamp);
        expect($entry->channel)->toBe('test-channel');
        });

    it('uses default channel', function () {
        $timestamp = Carbon::now();
        $entry = new Entry('INFO', 'Test message', [], $timestamp);
        
        expect($entry->channel)->toBe('default');
        });

    it('has string representation', function () {
        $timestamp = Carbon::create(2023, 1, 1, 12, 0, 0);
        $entry = new Entry('INFO', 'Test message', ['key' => 'value'], $timestamp, 'test-channel');
        
        $expected = '[2023-01-01 12:00:00] test-channel.INFO: Test message {"key":"value"}';
        expect((string) $entry)->toBe($expected);
        });

    it('has string representation without context', function () {
        $timestamp = Carbon::create(2023, 1, 1, 12, 0, 0);
        $entry = new Entry('WARNING', 'Warning message', [], $timestamp, 'test-channel');
        
        $expected = '[2023-01-01 12:00:00] test-channel.WARNING: Warning message';
        expect((string) $entry)->toBe($expected);
        });
});
