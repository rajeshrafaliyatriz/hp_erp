<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * A tenant's status and priority vocabularies: the fixed system sets merged
 * with the tenant's custom entries.
 *
 * Every controller that validates a status or priority resolves it here, so
 * "which values are legal" has exactly one answer. System entries are code
 * constants rather than seeded rows - they cannot be renamed or deleted, and
 * a new tenant needs no setup to have a working workflow.
 */
class TaskOptionSetService
{
    public const CATEGORIES = ['PENDING', 'IN-PROGRESS', 'ON HOLD', 'COMPLETED'];

    private const SYSTEM_STATUSES = [
        ['name' => 'PENDING', 'category' => 'PENDING', 'sort_order' => 10],
        ['name' => 'IN-PROGRESS', 'category' => 'IN-PROGRESS', 'sort_order' => 20],
        ['name' => 'ON HOLD', 'category' => 'ON HOLD', 'sort_order' => 30],
        ['name' => 'COMPLETED', 'category' => 'COMPLETED', 'sort_order' => 40],
    ];

    private const SYSTEM_PRIORITIES = [
        ['name' => 'High', 'sort_order' => 10],
        ['name' => 'Medium', 'sort_order' => 20],
        ['name' => 'Low', 'sort_order' => 30],
    ];

    /** @return array<int, array{id: ?string, name: string, category: string, color: ?string, sort_order: int, is_system: bool}> */
    public function statuses(int $sid, bool $activeOnly = true): array
    {
        $query = DB::table('task_management_statuses')->where('sub_institute_id', $sid);
        if ($activeOnly) {
            $query->where('active', true);
        }

        $custom = $query->orderBy('sort_order')->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'category' => (string) $row->category,
            'color' => $row->color,
            'sort_order' => (int) $row->sort_order,
            'is_system' => false,
            'active' => (bool) $row->active,
        ])->all();

        $system = array_map(fn (array $status) => $status + [
            'id' => null, 'color' => null, 'is_system' => true, 'active' => true,
        ], self::SYSTEM_STATUSES);

        $merged = array_merge($system, $custom);
        usort($merged, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $merged;
    }

    /** @return array<int, array{id: ?string, name: string, sort_order: int, sla_hours: ?int, is_system: bool}> */
    public function priorities(int $sid, bool $activeOnly = true): array
    {
        $query = DB::table('task_management_priorities')->where('sub_institute_id', $sid);
        if ($activeOnly) {
            $query->where('active', true);
        }

        $custom = $query->orderBy('sort_order')->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'sort_order' => (int) $row->sort_order,
            'sla_hours' => $row->sla_hours !== null ? (int) $row->sla_hours : null,
            'is_system' => false,
            'active' => (bool) $row->active,
        ])->all();

        $system = array_map(fn (array $priority) => $priority + [
            'id' => null, 'sla_hours' => null, 'is_system' => true, 'active' => true,
        ], self::SYSTEM_PRIORITIES);

        $merged = array_merge($system, $custom);
        usort($merged, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return $merged;
    }

    /**
     * Resolve a submitted status to what the row should store.
     *
     * A system category stores itself with no label. A tenant's custom name
     * stores its CATEGORY in task.status (so every board, summary and
     * transition keeps working) and the name in task.status_label.
     *
     * @return array{status: string, label: ?string}|null null = not a legal value
     */
    public function resolveStatus(int $sid, string $value): ?array
    {
        $value = trim($value);

        foreach (self::CATEGORIES as $category) {
            if (strcasecmp($value, $category) === 0) {
                return ['status' => $category, 'label' => null];
            }
        }

        $custom = DB::table('task_management_statuses')
            ->where('sub_institute_id', $sid)
            ->where('active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->first();

        return $custom
            ? ['status' => (string) $custom->category, 'label' => (string) $custom->name]
            : null;
    }

    /** The stored priority name, or null when the value is not legal. */
    public function resolvePriority(int $sid, string $value): ?string
    {
        $value = trim($value);

        foreach (self::SYSTEM_PRIORITIES as $priority) {
            if (strcasecmp($value, $priority['name']) === 0) {
                return $priority['name'];
            }
        }

        $custom = DB::table('task_management_priorities')
            ->where('sub_institute_id', $sid)
            ->where('active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
            ->value('name');

        return $custom !== null ? (string) $custom : null;
    }
}
