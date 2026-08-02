<?php

namespace App\Services\TaskManagement;

/**
 * The legal moves between task statuses.
 *
 * Kept as data so the rule is inspectable: a task moves forward through
 * PENDING -> IN-PROGRESS -> COMPLETED, can be parked ON HOLD from any open
 * state, and resumes from ON HOLD. COMPLETED only reopens to IN-PROGRESS
 * (rework); it never silently becomes PENDING again, because that erases the
 * fact that work happened.
 */
class TaskStatusTransitionService
{
    /** @var array<string, array<int, string>> */
    private const ALLOWED = [
        'PENDING' => ['IN-PROGRESS', 'ON HOLD', 'COMPLETED'],
        'IN-PROGRESS' => ['ON HOLD', 'COMPLETED', 'PENDING'],
        'ON HOLD' => ['PENDING', 'IN-PROGRESS', 'COMPLETED'],
        'COMPLETED' => ['IN-PROGRESS'],
    ];

    public function allows(?string $from, string $to): bool
    {
        $from = $this->normalise($from);
        $to = $this->normalise($to);

        // Restating the current status is a no-op, not a violation - remarks
        // still update, which is how "add a progress note" works.
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function message(?string $from, string $to): string
    {
        $from = $this->normalise($from);
        $to = $this->normalise($to);

        if ($from === 'COMPLETED') {
            return 'A completed task can only be reopened to In Progress.';
        }

        return "A task cannot move from {$from} to {$to}.";
    }

    /** The table holds historical spellings; judge transitions on one form. */
    private function normalise(?string $status): string
    {
        $status = strtoupper(trim((string) $status));

        return match ($status) {
            'IN PROGRESS', 'IN-PROGRES' => 'IN-PROGRESS',
            '' => 'PENDING',
            default => $status,
        };
    }
}
