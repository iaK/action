# Changelog

All notable changes to `laravel-action` will be documented in this file.

## Unreleased

**Breaking changes**

- `Profile::memoryUsed()`, `startMemory()`, `endMemory()` and `peakMemory()` no longer take a unit argument and now return a `MemorySize` value object. Use `->bytes()`, `->in('KB')` or `->format()` on the result. This also fixes fractional unit conversions being silently truncated to integers.
- `Memory::formattedMemory()` was replaced by `Memory::size()`, returning a `MemorySize`.
- `MemoryFormatter` was replaced by the `MemorySize` value object.
- `only()`, `without()`, `except()`, `profile()`, `queries()` and `logs()` now reject container aliases and non-action classes with a clear exception instead of failing later with a fatal error.

**Improvements**

- PHPStan level 9 (up from 6) with full generics: `Action::test()` returns `Testable<static>`, `Action::fake()` returns `static&MockInterface`, and all inspection callbacks have typed closure signatures, so editors autocomplete `$queries`, `$logs` and `$profiles` inside callbacks.

## v1.3.0 - 2025-11-04

Added with(), without(), profile(), queries(), logs() as well as the ability to forward events.
