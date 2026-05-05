# Changelog

All notable changes to `scheduled-tasks-health-check` will be documented in this file.

## 1.0.5 - 2026-05-06

### Fixed
- Brand-new tasks (no `last_finished_at` and no `last_failed_at`) were reported as **failed** because `null >= null` evaluates to `true` in PHP. They are now reported as `never_run` and counted as healthy.
- Comparing Carbon instances against `null` was implicit and version-dependent. Status resolution is now explicit and works correctly across Carbon 2 and Carbon 3 (signed-vs-absolute `diffInMinutes` collapsed via `max(0, …)`).

### Added
- `STATUS_OK` / `STATUS_DELAYED` / `STATUS_FAILED` / `STATUS_NEVER_RUN` constants on `ScheduledTasksHealthCheck`.
- `defaultGraceTimeInMinutes(int)` setter — used when a row has `grace_time_in_minutes = NULL`.
- Restored Pest test infrastructure: `tests/Pest.php` referenced a non-existent `TestCase` class and the package shipped with zero runnable tests. Added `tests/TestCase.php` (Orchestra Testbench) plus 9 Pest tests covering all status transitions.
- PHPStan configuration (`phpstan.neon.dist`, level 5) — package previously had no static analysis at all.
- `format` / `format:check` composer scripts.

### Backwards compatibility
- The "fresh task no longer reports as failed" change is technically a behavioral change — if you were relying on that false positive to detect brand-new tasks, you will need to adapt. Existing healthy / failing / delayed semantics are unchanged.
