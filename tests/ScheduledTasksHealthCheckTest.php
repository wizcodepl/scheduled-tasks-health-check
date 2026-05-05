<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Enums\Status;
use WizcodePl\ScheduledTasksHealthCheck\ScheduledTasksHealthCheck;

uses(RefreshDatabase::class);

/**
 * Bare-bones replica of the Spatie schedule-monitor table — we only care
 * about the columns the check actually reads.
 */
beforeEach(function () {
    DB::statement(<<<'SQL'
        CREATE TABLE monitored_scheduled_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            last_finished_at TEXT NULL,
            last_failed_at TEXT NULL,
            grace_time_in_minutes INTEGER NULL
        )
    SQL);
});

function insertTask(array $row): void
{
    DB::table('monitored_scheduled_tasks')->insert(array_merge([
        'name' => 'task',
        'last_finished_at' => null,
        'last_failed_at' => null,
        'grace_time_in_minutes' => 5,
    ], $row));
}

it('returns ok when there are no monitored tasks', function () {
    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['total_tasks'])->toBe(0);
});

it('reports brand new tasks as never_run, not failed', function () {
    // Regression: previously `null >= null` evaluated to true and any task
    // that had never run was reported as failed.
    insertTask(['name' => 'fresh', 'last_finished_at' => null, 'last_failed_at' => null]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['failed_tasks'])->toBe(0)
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_NEVER_RUN);
});

it('reports a task as failed when last_failed_at is more recent than last_finished_at', function () {
    insertTask([
        'name' => 'broken',
        'last_finished_at' => Carbon::now()->subHour()->toDateTimeString(),
        'last_failed_at' => Carbon::now()->subMinute()->toDateTimeString(),
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_FAILED);
});

it('reports a task as failed when it only ever failed (never finished)', function () {
    insertTask([
        'name' => 'always-failed',
        'last_finished_at' => null,
        'last_failed_at' => Carbon::now()->subMinute()->toDateTimeString(),
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_FAILED);
});

it('reports a task as ok when the last finish is more recent than the last failure', function () {
    insertTask([
        'name' => 'recovered',
        'last_finished_at' => Carbon::now()->subMinute()->toDateTimeString(),
        'last_failed_at' => Carbon::now()->subHour()->toDateTimeString(),
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_OK);
});

it('reports a task as delayed when grace time has elapsed', function () {
    insertTask([
        'name' => 'late',
        'last_finished_at' => Carbon::now()->subMinutes(20)->toDateTimeString(),
        'last_failed_at' => null,
        'grace_time_in_minutes' => 5,
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_DELAYED);
});

it('respects per-task grace_time_in_minutes', function () {
    insertTask([
        'name' => 'patient',
        'last_finished_at' => Carbon::now()->subMinutes(20)->toDateTimeString(),
        'grace_time_in_minutes' => 30,
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::ok())
        ->and($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_OK);
});

it('falls back to the default grace time when the row has none', function () {
    insertTask([
        'name' => 'no-grace',
        'last_finished_at' => Carbon::now()->subMinutes(11)->toDateTimeString(),
        'grace_time_in_minutes' => null,
    ]);

    // Default is 5min; 11min elapsed → delayed.
    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_DELAYED);

    // Bump the default to 30min — now the same row should look healthy.
    $result = (new ScheduledTasksHealthCheck)->defaultGraceTimeInMinutes(30)->run();

    expect($result->meta['tasks'][0]['status'])->toBe(ScheduledTasksHealthCheck::STATUS_OK);
});

it('counts only failed and delayed in the failing tally', function () {
    insertTask(['name' => 'fresh']);
    insertTask([
        'name' => 'broken',
        'last_failed_at' => Carbon::now()->subMinute()->toDateTimeString(),
    ]);
    insertTask([
        'name' => 'late',
        'last_finished_at' => Carbon::now()->subHour()->toDateTimeString(),
    ]);
    insertTask([
        'name' => 'happy',
        'last_finished_at' => Carbon::now()->subMinute()->toDateTimeString(),
    ]);

    $result = (new ScheduledTasksHealthCheck)->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->meta['total_tasks'])->toBe(4)
        ->and($result->meta['failed_tasks'])->toBe(2);
});
