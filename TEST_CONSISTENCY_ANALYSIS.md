# Test Consistency Analysis & Recommendations

## Current State Analysis

### 1. **Describe Block Usage** ❌ Inconsistent

**Files USING `describe()`:**
- ✅ `tests/Testing/Traits/LogProxyTraitTest.php` - Uses `describe('LogProxyTrait', ...)`
- ✅ `tests/Testing/QueryListenerTest.php` - Uses `describe('QueryListener', ...)`
- ✅ `tests/EventsTest.php` - Uses multiple `describe()` blocks (EmitsEvents, HandlesEvents, Event Action Integration)

**Files NOT using `describe()`:**
- ❌ `tests/Testing/Traits/ProfileProxyTraitTest.php`
- ❌ `tests/Testing/Traits/DatabaseCallProxyTraitTest.php`
- ❌ `tests/Testing/TestableActionTest.php`
- ❌ `tests/Testing/Results/QueryTest.php`
- ❌ `tests/Testing/Results/ProfileTest.php`
- ❌ `tests/Testing/Results/EntryTest.php`
- ❌ `tests/Testing/RuntimeProfilerTest.php`
- ❌ `tests/ActionTest.php`
- ❌ `tests/Testing/ProfileFeatureTest.php`
- ❌ `tests/Testing/LogTest.php`
- ❌ `tests/Testing/DatabaseTest.php`
- ❌ `tests/Testing/CombinationTest.php`

**Special Cases:**
- ⚠️ `tests/Testing/LogListenerTest.php` - Uses PHPUnit class-based tests (should be converted to Pest)
- ✅ `tests/ArchTest.php` - Uses `arch()` (correct for architecture tests)

### 2. **Test Naming Conventions** ⚠️ Inconsistent

**Patterns found:**
- "can [action]" - Most common (good)
- "handles [action]" - Some files
- "creates [action]" - Some files
- "throws exception [when/for]" - Error cases
- "has [property]" - Property checks
- "uses [default]" - Default behavior
- "maintains [state]" - State preservation
- "includes [info]" - Information checks
- "does not [include]" - Negative cases

**Issues:**
- Some tests start with verbs directly: "handle calls", "creates log listener"
- Some are more descriptive, others very brief
- Inconsistent verb choice for similar concepts

### 3. **File Placement** ✅ Mostly Consistent

**Good:**
- Tests mirror source structure:
  - `src/Testing/Results/` → `tests/Testing/Results/`
  - `src/Testing/Traits/` → `tests/Testing/Traits/`
  - `src/Testing/` → `tests/Testing/`

**Issues:**
- `tests/EventsTest.php` tests both `EmitsEvents` and `HandlesEvents` which are in `src/` root
- Could be split into separate files or moved to match structure better

### 4. **Test Framework Usage** ⚠️ Mixed

- **Pest PHP**: 16 files
- **PHPUnit (class-based)**: 1 file (`LogListenerTest.php`)
- This is inconsistent - all tests should use Pest for consistency

## Recommendations

### Best Practice: Pest PHP Test Structure

Based on Pest PHP best practices, tests should:

1. **Use `describe()` blocks** to group related tests logically
2. **Have descriptive test names** following a consistent pattern
3. **Use `beforeEach()`** for setup when needed
4. **Mirror source structure** in test structure

### Recommended Patterns

#### 1. **Describe Block Usage**

**Rule:** Every test file should use a `describe()` block that matches the class/component being tested.

```php
describe('ComponentName', function () {
    // tests here
});
```

**Exception:** Very simple test files with just 2-3 tests can omit `describe()` if they're self-explanatory, but consistency is preferred.

#### 2. **Test Naming Convention**

**Recommended pattern:** Use "it" followed by a descriptive action

- ✅ `it('can create [component]', ...)`
- ✅ `it('handles [action] correctly', ...)`
- ✅ `it('throws exception when [condition]', ...)`
- ✅ `it('returns [expected] for [condition]', ...)`
- ✅ `it('has [property/behavior]', ...)`

**Avoid:**
- ❌ Starting directly with verbs: "handle calls" → should be "it handles calls"
- ❌ Inconsistent verb forms: mix of "can", "handles", "creates"

#### 3. **Grouping Tests with Describe**

For complex components with multiple features:

```php
describe('ComponentName', function () {
    describe('initialization', function () {
        // initialization tests
    });
    
    describe('core functionality', function () {
        // core tests
    });
    
    describe('edge cases', function () {
        // edge case tests
    });
});
```

Or use single-level describe if grouping isn't needed:

```php
describe('ComponentName', function () {
    it('can do x', ...);
    it('can do y', ...);
    // etc
});
```

## Specific File Recommendations

### High Priority (Inconsistencies to Fix)

1. **`LogListenerTest.php`**
   - ❌ Currently uses PHPUnit class-based tests
   - ✅ Should be converted to Pest format
   - ✅ Should use `describe('LogListener', ...)`
   - ✅ Should mirror `QueryListenerTest.php` structure

2. **All trait test files should have `describe()`:**
   - `ProfileProxyTraitTest.php`
   - `DatabaseCallProxyTraitTest.php`
   - (LogProxyTraitTest.php already has it ✅)

3. **All Results test files should have `describe()`:**
   - `QueryTest.php` → `describe('Query', ...)`
   - `ProfileTest.php` → `describe('Profile', ...)`
   - `EntryTest.php` → `describe('Entry', ...)`

4. **Other test files should have `describe()`:**
   - `RuntimeProfilerTest.php` → `describe('RuntimeProfiler', ...)`
   - `TestableActionTest.php` → `describe('Testable', ...)`
   - `ActionTest.php` → `describe('Action', ...)`

### Medium Priority (Consistency Improvements)

5. **Feature test files (can stay flat or group):**
   - `ProfileFeatureTest.php` → Could use `describe('Profile Feature', ...)`
   - `LogTest.php` → Could use `describe('Log Feature', ...)`
   - `DatabaseTest.php` → Could use `describe('Database Feature', ...)`
   - `CombinationTest.php` → **Should be merged into `TestableActionTest.php`** (both test `Testable` class)

6. **Test naming consistency:**
   - Standardize verb usage: prefer "can" for capabilities, "throws" for errors
   - Make descriptions more consistent in structure

## Implementation Plan

### Phase 1: Critical Fixes
1. Convert `LogListenerTest.php` from PHPUnit to Pest
2. Add `describe()` blocks to all test files that lack them
3. Ensure all trait and results tests follow same pattern

### Phase 2: Consistency
4. Standardize test naming patterns across all files
5. Review and align test descriptions for clarity

### Phase 3: Organization
6. Consider if `EventsTest.php` should be split or reorganized
7. Ensure all files follow the same structure pattern

## Example: Before & After

### Before (ProfileProxyTraitTest.php):
```php
<?php

use Iak\Action\Traits\ProfileProxyTrait;

it('can be used in a proxy class', function () {
    // ...
});

it('can handle action execution through the proxy', function () {
    // ...
});
```

### After (Recommended):
```php
<?php

use Iak\Action\Traits\ProfileProxyTrait;
use Iak\Action\Tests\TestClasses\ClosureAction;

describe('ProfileProxyTrait', function () {
    it('can be used in a proxy class', function () {
        // ...
    });

    it('can handle action execution through the proxy', function () {
        // ...
    });
});
```

## Additional Recommendations

### File Organization: `CombinationTest.php`

**Analysis:** `CombinationTest.php` and `TestableActionTest.php` both test the `Testable` class:
- `TestableActionTest.php` tests mocking features (`without()`, `only()`)
- `CombinationTest.php` tests method chaining (`profile()`, `queries()`, `logs()` together)

**Recommendation:** Merge `CombinationTest.php` into `TestableActionTest.php` and organize with `describe()` blocks:

```php
describe('Testable', function () {
    describe('mocking', function () {
        // without, only tests
    });
    
    describe('feature combinations', function () {
        // profile + queries, profile + logs, etc.
    });
});
```

This consolidates all `Testable` class tests in one place and better reflects that they're testing the same class.

## Summary

**Current State:** 3/17 test files use `describe()`, 1 uses PHPUnit class-based, naming is inconsistent, `CombinationTest.php` should be merged.

**Target State:** All Pest tests use `describe()`, consistent naming patterns, all tests follow same structure, `CombinationTest.php` merged into `TestableActionTest.php`.

**Benefits:**
- Better test organization and readability
- Easier to find and understand tests
- Consistent developer experience
- Better test output in Pest CLI

