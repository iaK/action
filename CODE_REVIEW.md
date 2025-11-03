# Code Review: `/src` Directory

## Critical Issues

- [X] **`Testable.php` - `without()` method doesn't use return value**: Line 41: Uses `collect($classes)->map()` but discards the return value. Since you're only using side effects (container binding), use `each()` or a simple `foreach` instead.

- [X] **`Testable.php` - Wrong error message**: Line 61: Error message says "Invalid class passed to within" but the method is called `without`.

- [X] **`Testable.php` - Unused property**: Line 20: The `$without` property is declared but never actually used in the codebase.

## Design & Architecture

- [X] **`Testable.php` - Too many public properties**: Lines 19-35: Many internal state properties are public when they should be `protected` or `private`.

- [X] **`Testable.php` - Complex closure nesting in `handle()`**: Lines 172-210: The nested closures that build the execution pipeline are hard to follow. Consider extracting methods or using a pipeline/builder pattern.

- [ ] **`Testable.php` - Recursive rebinding in `bindProxyWrapper()`**: Lines 308-335: The method recursively calls itself (line 329), which could lead to stack overflow if misused. Consider extracting to a helper or refactoring.

- [X] **`HandlesEvents.php` - Static variable in `propagateToAncestor()`**: Line 52: The static `$propagatedTo` array could persist across test runs or cause unexpected behavior. Should be instance state.

- [ ] **`HandlesEvents.php` - Complex `debug_backtrace()` logic**: Lines 54-91: Heavy reliance on `debug_backtrace()` is brittle and performance-intensive. Consider dependency injection or explicit propagation mechanism.

## Code Quality & Consistency

- [X] **`Testable.php` - Duplicate proxy creation methods**: Methods `createProfileProxyClass()`, `createDatabaseProxyClass()`, and `createLogProxyClass()` have nearly identical code. Extract common logic into a generic method.

- [X] **`Traits/*ProxyTrait.php` - Nearly identical traits**: All three proxy traits are almost identical, only differing by which listener/profiler they use. Could be consolidated with a strategy pattern or shared base.

- [ ] **`Testable.php` - Duplicate logic in `profile()`, `queries()`, `logs()`**: These three methods share almost identical structure. Extract common logic.

- [X] **`RuntimeProfiler.php` - Public properties**: Lines 11-16: Properties should be `private` with getters if needed.

- [X] **`Profile.php` - Untyped `$class` property**: Line 10: Should be `public string $class` instead of just `public $class`.

- [ ] **`Profile.php` - Duplicated memory conversion logic**: The `convertToUnit()` and `formatBytes()` methods share conversion logic that could be extracted.

## Error Handling & Validation

- [ ] **`Testable.php` - Missing validation**: Some methods don't validate that arrays aren't empty before iterating.

- [X] **`HandlesEvents.php` - `forwardEvents()` method name**: Line 40: Method name `forwardEvents()` suggests forwarding events, but it actually sets which events to forward. Consider renaming for clarity.

## Performance

- [ ] **`HandlesEvents.php` - Repeated reflection calls**: The `getAllowedEvents()` method (lines 97-114) uses reflection on every call. Should cache the result per class.

- [X] **`QueryListener.php` - Listener never unregistered**: Line 35: `DB::listen()` is registered in constructor and never removed. Could lead to memory leaks or unexpected behavior in long-running processes.

- [ ] **`Testable.php` - Repeated `class_exists()` checks**: Multiple calls to `class_exists()` for the same classes. Could cache results.

## Type Safety

- [ ] **`Action.php` - Union return type**: Line 40: Returning `MockInterface|LegacyMockInterface` suggests an incomplete migration. Consider if both are needed.

- [X] **`Traits/*ProxyTrait.php` - Missing type hints**: Properties like `$testable` and `$action` lack proper type hints (should be `Testable` and `Action` types).

- [ ] **`LogListener.php` - Anonymous class**: Lines 24-48: The anonymous handler class should be extracted to a named class for better testability and clarity.

## Best Practices

- [ ] **`Testable.php` - Unused method**: `profileMainAction()` method (lines 450-458) is never called. Remove or document why it exists.

- [X] **`Action.php` - Magic method docblock**: Line 12: Using `@method mixed handle(...$args)` in docblock instead of making it a proper abstract method or interface contract.

- [ ] **`EmitsEvents.php` - Constructor validation in attribute**: While it works, having validation logic in an attribute constructor is unconventional. Consider validating at usage time.

- [ ] **`HandlesEvents.php` - `generateEventName()` uses `static::class`**: Line 133: Should use instance method to ensure instance-scoped events.

## Recommendations Priority

### High Priority
1. Fix `without()` method to use `each()` instead of `map()`
2. Remove or properly use `$without` property
3. Cache `getAllowedEvents()` results
4. ~~Properly unregister query listeners~~ ✓ Fixed
5. Extract duplicate proxy creation code

### Medium Priority
6. Replace `eval()` with safer approach
7. Make public properties private/protected
8. Refactor nested closures in `handle()`
9. Consolidate duplicate trait/proxy methods

### Low Priority
10. Add missing type hints
11. Improve error message consistency
12. Remove unused methods

