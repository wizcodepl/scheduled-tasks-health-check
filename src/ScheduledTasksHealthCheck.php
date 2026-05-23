<?php

declare(strict_types=1);

namespace WizcodePl\ScheduledTasksHealthCheck;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Reads `monitored_scheduled_tasks` (Spatie's schedule monitor table) and
 * reports tasks with their full schedule: cron, last finish/fail, next run.
 *
 * Per-task status is decided as follows:
 *   - `never_run`  — task has neither finished nor failed yet (brand new
 *                    monitor or nothing scheduled to run since registering).
 *                    Treated as **OK**, not as a failure.
 *   - `failed`     — last failure is more recent than the last success
 *                    (or the task only ever failed).
 *   - `delayed`    — task last finished, but more than `grace_time_in_minutes`
 *                    has passed since then.
 *   - `ok`         — anything else.
 */
class ScheduledTasksHealthCheck extends Check
{
    public const STATUS_OK = 'ok';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEVER_RUN = 'never_run';

    protected int $defaultGraceTimeInMinutes = 5;

    public function defaultGraceTimeInMinutes(int $minutes): self
    {
        $this->defaultGraceTimeInMinutes = $minutes;

        return $this;
    }

    public function run(): Result
    {
        $tasks = $this->fetchTasks();
        $now = Carbon::now();
        $taskDetails = [];
        $failingCount = 0;

        foreach ($tasks as $task) {
            $detail = $this->evaluate($task, $now);
            $taskDetails[] = $detail;

            if (in_array($detail['status'], [self::STATUS_FAILED, self::STATUS_DELAYED], true)) {
                $failingCount++;
            }
        }

        $totalTasks = count($taskDetails);

        $result = Result::make()->meta([
            'total_tasks' => $totalTasks,
            'failed_tasks' => $failingCount,
            'tasks' => $taskDetails,
        ]);

        return $failingCount > 0
            ? $result->failed("Some scheduled tasks are failing or delayed ({$failingCount}/{$totalTasks})")
            : $result->ok("All scheduled tasks are running properly ({$totalTasks})");
    }

    /**
     * @return iterable<int, object{name: string, cron_expression: ?string, last_finished_at: ?string, last_failed_at: ?string, grace_time_in_minutes: ?int}>
     */
    protected function fetchTasks(): iterable
    {
        return DB::table('monitored_scheduled_tasks')
            ->select(['name', 'cron_expression', 'last_finished_at', 'last_failed_at', 'grace_time_in_minutes'])
            ->get();
    }

    /**
     * @param  object{name: string, cron_expression: ?string, last_finished_at: ?string, last_failed_at: ?string, grace_time_in_minutes: ?int}  $task
     * @return array{name: string, cron: ?string, last_finished_at: string, last_finished_diff: ?string, last_failed_at: string, next_run_at: ?string, next_run_diff: ?string, grace_time_in_minutes: int, status: string}
     */
    protected function evaluate(object $task, Carbon $now): array
    {
        $lastFinishedAt = $task->last_finished_at !== null ? Carbon::parse($task->last_finished_at) : null;
        $lastFailedAt = $task->last_failed_at !== null ? Carbon::parse($task->last_failed_at) : null;
        $graceTime = $task->grace_time_in_minutes ?? $this->defaultGraceTimeInMinutes;

        $nextRun = null;
        if ($task->cron_expression !== null) {
            try {
                $nextRun = Carbon::instance((new CronExpression($task->cron_expression))->getNextRunDate());
            } catch (\Throwable) {
                $nextRun = null;
            }
        }

        $status = $this->resolveStatus($lastFinishedAt, $lastFailedAt, $graceTime, $now);

        return [
            'name' => $task->name,
            'cron' => $task->cron_expression,
            'last_finished_at' => $lastFinishedAt?->toDateTimeString() ?? 'N/A',
            'last_finished_diff' => $lastFinishedAt?->diffForHumans(),
            'last_failed_at' => $lastFailedAt?->toDateTimeString() ?? 'N/A',
            'next_run_at' => $nextRun?->toDateTimeString(),
            'next_run_diff' => $nextRun?->diffForHumans(),
            'grace_time_in_minutes' => $graceTime,
            'status' => $status,
        ];
    }

    private function resolveStatus(?Carbon $lastFinishedAt, ?Carbon $lastFailedAt, int $graceTime, Carbon $now): string
    {
        if ($lastFinishedAt === null && $lastFailedAt === null) {
            return self::STATUS_NEVER_RUN;
        }

        $failedAfterFinished = $lastFailedAt !== null
            && ($lastFinishedAt === null || $lastFailedAt->greaterThan($lastFinishedAt));

        if ($failedAfterFinished) {
            return self::STATUS_FAILED;
        }

        if ($lastFinishedAt !== null) {
            $minutesSinceFinish = max(0, (int) $lastFinishedAt->diffInMinutes($now));

            if ($minutesSinceFinish > $graceTime) {
                return self::STATUS_DELAYED;
            }
        }

        return self::STATUS_OK;
    }
}
