<?php

namespace WizcodePl\ScheduledTasksHealthCheck;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class ScheduledTasksHealthCheck extends Check
{
    public function run(): Result
    {
        $tasks = DB::table('monitored_scheduled_tasks')
            ->select([
                'name',
                'last_finished_at',
                'last_failed_at',
                'grace_time_in_minutes',
            ])
            ->get();

        $now = Carbon::now();
        $totalTasks = $tasks->count();
        $failedTasks = 0;
        $taskDetails = [];

        foreach ($tasks as $task) {
            $lastFinishedAt = $task->last_finished_at ? Carbon::parse($task->last_finished_at) : null;
            $lastFailedAt = $task->last_failed_at ? Carbon::parse($task->last_failed_at) : null;
            $graceTime = $task->grace_time_in_minutes ?? 5;

            $isDelayed = $lastFinishedAt && $lastFinishedAt->diffInMinutes($now) > $graceTime;
            $status = 'ok';

            if ($lastFailedAt) {
                $status = 'failed';
                $failedTasks++;
            } elseif ($isDelayed) {
                $status = 'delayed';
                $failedTasks++;
            }

            $taskDetails[] = [
                'name' => $task->name,
                'last_finished_at' => $lastFinishedAt?->toDateTimeString() ?? 'N/A',
                'last_failed_at' => $lastFailedAt?->toDateTimeString() ?? 'N/A',
                'grace_time_in_minutes' => $graceTime,
                'status' => $status,
            ];
        }

        $result = Result::make()
            ->meta([
                'total_tasks' => $totalTasks,
                'failed_tasks' => $failedTasks,
                'tasks' => $taskDetails,
            ]);

        return $failedTasks > 0
            ? $result->failed("Some scheduled tasks are failing or delayed ({$failedTasks}/{$totalTasks})")
            : $result->ok("All scheduled tasks are running properly ({$totalTasks})");
    }
}
