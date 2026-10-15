# Changelog - Version 6.0

## New Features

### Interface-based Architecture
- **TransitionConditionInterface**: New interface for transition conditions with `canTransition(?array $data): bool` method
- **StateActionInterface**: New interface for state actions with `execute(?array $data): void` method
- These interfaces replace the previous closure-based approach, providing better type safety and code organization

### Enhanced Documentation
- Added comprehensive documentation in separate markdown files:
  - `docs/basic-usage.md` - Getting started guide
  - `docs/auto-transition.md` - Auto-transition feature documentation
  - `docs/error-handling.md` - Error handling strategies
  - `docs/advanced-features.md` - Advanced usage patterns
- Updated README with clearer examples using the new interface-based approach

### PHP 8.4 Support
- Added support for PHP 8.4
- Fixed nullable parameter deprecations for PHP 8.4 compatibility

### Improved Testing and CI/CD
- Updated PHPUnit to version 10.5 or 11.5
- Enhanced GitHub Actions workflow with better container options
- Improved Psalm configuration for static analysis

## Bug Fixes

- Fixed nullable parameter type hints to comply with PHP 8.4 requirements
- Fixed Psalm static analysis issues
- Improved type safety throughout the codebase

## Breaking Changes

| Component | Before (5.x) | After (6.0) | Description |
|-----------|--------------|-------------|-------------|
| **Transition Constructor** | `new Transition($from, $to, Closure $fn)` | `new Transition($from, $to, TransitionConditionInterface $condition)` | Closures replaced with `TransitionConditionInterface` implementation |
| **Transition::create()** | `Transition::create($from, $to, Closure $fn)` | `Transition::create($from, $to, TransitionConditionInterface $condition)` | Static factory method now requires interface instead of closure |
| **Transition::createMultiple()** | `Transition::createMultiple(array $from, $to, Closure $fn)` | `Transition::createMultiple(array $from, $to, TransitionConditionInterface $condition)` | Batch transition creation now uses interface |
| **State Constructor** | `new State($name, Closure $fn)` | `new State($name, StateActionInterface $action)` | Closures replaced with `StateActionInterface` implementation |
| **PHP Version** | `>=8.1 <8.4` | `>=8.3 <8.6` | Minimum PHP version raised to 8.3, added support for 8.4 and 8.5 |
| **PHPUnit Version** | `^9.6` | `^10.5\|^11.5` | Updated to latest PHPUnit versions |
| **Psalm Version** | `^5.9` | `^5.9\|^6.13` | Added support for Psalm 6.x |

## Upgrade Path from 5.x to 6.0

### Step 1: Update PHP Version
Ensure your environment is running PHP 8.3 or higher:
```bash
php -v  # Should show 8.3.x, 8.4.x, or 8.5.x
```

### Step 2: Update Dependencies
Update your `composer.json`:
```bash
composer require byjg/statemachine:^6.0
composer update
```

### Step 3: Replace Closure-based Transition Conditions

**Before (5.x):**
```php
$transition = new Transition(
    $stateA,
    $stateB,
    function(?array $data): bool {
        return isset($data['approved']) && $data['approved'] === true;
    }
);
```

**After (6.0):**
```php
use ByJG\StateMachine\TransitionConditionInterface;

$condition = new class implements TransitionConditionInterface {
    public function canTransition(?array $data): bool {
        return isset($data['approved']) && $data['approved'] === true;
    }
};

$transition = new Transition($stateA, $stateB, $condition);
```

**Alternative:** Create reusable condition classes:
```php
class ApprovedCondition implements TransitionConditionInterface {
    public function canTransition(?array $data): bool {
        return isset($data['approved']) && $data['approved'] === true;
    }
}

$transition = new Transition($stateA, $stateB, new ApprovedCondition());
```

### Step 4: Replace Closure-based State Actions

**Before (5.x):**
```php
$state = new State('PROCESSED', function(?array $data): void {
    // Process the data
    echo "Processing: " . json_encode($data);
});
```

**After (6.0):**
```php
use ByJG\StateMachine\StateActionInterface;

$action = new class implements StateActionInterface {
    public function execute(?array $data): void {
        // Process the data
        echo "Processing: " . json_encode($data);
    }
};

$state = new State('PROCESSED', $action);
```

**Alternative:** Create reusable action classes:
```php
class ProcessAction implements StateActionInterface {
    public function execute(?array $data): void {
        echo "Processing: " . json_encode($data);
    }
}

$state = new State('PROCESSED', new ProcessAction());
```

### Step 5: Update Tests
If you're using PHPUnit, update your test files to be compatible with PHPUnit 10.5 or 11.5. The most common changes include:
- Update namespace imports if needed
- Verify assertion methods are still available
- Check for deprecated features

### Step 6: Run Static Analysis
Run Psalm to ensure type safety:
```bash
composer psalm
```

### Step 7: Run Tests
Verify everything works:
```bash
composer test
```

## Benefits of Upgrading

1. **Better Type Safety**: Interface-based approach provides compile-time type checking
2. **Improved Testability**: Conditions and actions can be easily mocked and tested in isolation
3. **Code Reusability**: Create reusable condition and action classes instead of duplicating closures
4. **Better IDE Support**: Interfaces provide better autocomplete and inline documentation
5. **PHP 8.4 Compatibility**: Future-proof your code with the latest PHP features
6. **Enhanced Documentation**: Comprehensive guides for all features

## Notes

- All closures must be converted to interface implementations
- There is no backward compatibility layer for closures
- The migration is straightforward and can be done incrementally by updating one transition/state at a time
- Consider creating a library of common conditions and actions for your application
