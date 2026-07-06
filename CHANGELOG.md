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
- `only()` now also mocks constructor-injected child actions, not just actions resolved during `handle()`.
- Auto-mocked actions return a zero value matching their declared `handle()` return type (`''`, `0`, `false`, `[]`) instead of `null`.
- Declared the runtime dependencies (`illuminate/support`, `illuminate/database`, `monolog/monolog`, `nesbot/carbon`) and suggested `mockery/mockery` for the testing helpers.
- The `profile()`/`queries()`/`logs()` test instruments now share one generic `Instrumentation` descriptor internally instead of three copies of the registration, interception and collection machinery — groundwork for future instruments.

**Bug fixes**

- Falsy mocked return values (`false`, `0`, `''`) passed to `without()` are no longer silently converted to `null`.
- Fixed a fatal error when profiling or auto-mocking actions whose `handle()` declares a return type.
- Fixed a crash in the event-cleanup destructor when it fired between tests while facades pointed at a flushed application.
- Container bindings replaced by `profile()`/`queries()`/`logs()` proxies are restored after `handle()`, and the `only()` hook deactivates itself instead of intercepting resolutions (and leaking the `Testable`) forever.
- `profile()`/`queries()`/`logs()` now reject `final` action classes with a clear exception instead of an uncatchable fatal when the proxy is created.
- `ProfileListener` removes its `action.record_memory.*` listeners after the profiled run instead of leaking them.

## v1.3.0 - 2025-11-04

Added with(), without(), profile(), queries(), logs() as well as the ability to forward events.
