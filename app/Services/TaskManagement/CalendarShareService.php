<?php

namespace App\Services\TaskManagement;

use Illuminate\Support\Facades\DB;

/**
 * Voluntary, per-person calendar sharing grants — see the migration's own
 * docblock for why this exists alongside the tiered visibility model rather
 * than replacing it.
 *
 * Two directions, two methods, never confused for each other:
 *   outgoing()  whom I (the owner) have granted access to
 *   incoming()  whose calendars I (the viewer) may overlay
 */
class CalendarShareService
{
    public const EVERYONE = 0;

    public function share(int $ownerUserId, int $viewerUserId, bool $canEdit, int $subInstituteId, int $actorId, ?string $color = null): void
    {
        DB::table('task_management_calendar_shares')->updateOrInsert(
            ['owner_user_id' => $ownerUserId, 'viewer_user_id' => $viewerUserId],
            ['sub_institute_id' => $subInstituteId, 'can_edit' => $canEdit, 'color' => $color, 'created_by' => $actorId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    public function unshare(int $ownerUserId, int $shareId): bool
    {
        return (bool) DB::table('task_management_calendar_shares')
            ->where('id', $shareId)->where('owner_user_id', $ownerUserId)->delete();
    }

    /** Whom $ownerUserId has granted access to — their own outgoing grants. */
    public function outgoing(int $ownerUserId, int $subInstituteId): \Illuminate\Support\Collection
    {
        return DB::table('task_management_calendar_shares as s')
            ->leftJoin('tbluser as viewer', 'viewer.id', '=', 's.viewer_user_id')
            ->where('s.owner_user_id', $ownerUserId)
            ->where('s.sub_institute_id', $subInstituteId)
            ->orderBy('s.id')
            ->get([
                's.id', 's.viewer_user_id', 's.can_edit', 's.color',
                DB::raw("TRIM(CONCAT_WS(' ', viewer.first_name, viewer.middle_name, viewer.last_name)) as viewer_name"),
            ]);
    }

    /**
     * Whose calendars $viewerUserId may overlay — self, always; anyone who
     * shared with them specifically or with "everyone"; subordinates if they
     * manage people; literally everyone in the tenant if they're
     * TASK_PRIVILEGED (mirroring CalendarVisibilityService's own rule, so the
     * feed picker never offers a feed the visibility layer would then hide).
     *
     * @return array<int, array{user_id:int, name:string, color:?string}>
     */
    public function incoming(int $viewerUserId, int $subInstituteId): array
    {
        $visibility = app(CalendarVisibilityService::class);

        // The color THIS viewer sees for an owner's feed is the one that
        // owner picked for them specifically - a share aimed at "everyone"
        // only fills in where no personal one exists, same precedence a
        // direct grant already takes over a blanket one everywhere else in
        // this service. Computed once, used by BOTH branches below - a
        // privileged viewer can still have been given a real color by an
        // owner who shared with them specifically, and the privileged
        // branch used to hardcode `color: null` for everyone regardless,
        // silently discarding every color a privileged viewer was ever given.
        $shareRows = DB::table('task_management_calendar_shares')
            ->where('sub_institute_id', $subInstituteId)
            ->where(fn ($query) => $query->where('viewer_user_id', $viewerUserId)->orWhere('viewer_user_id', self::EVERYONE))
            ->get(['owner_user_id', 'viewer_user_id', 'color']);

        $colorByOwner = [];
        foreach ($shareRows->sortBy(fn ($row) => (int) $row->viewer_user_id === $viewerUserId ? 0 : 1) as $row) {
            $colorByOwner[(int) $row->owner_user_id] ??= $row->color;
        }

        /*
         * A person's OWN chosen color (UserPreferences::task_card_color),
         * tenant-wide - the fallback under a viewer-specific share color, not
         * instead of it. An owner who shared with THIS viewer using a
         * specific color still shows that way to them; everyone else sees
         * the owner's own pick. Resolved here, once, so the wire format and
         * every consumer (the dot, the chip accent) stay exactly as they
         * were - this just widens what feeds a non-null color.
         */
        $personalColorByUser = [];
        foreach (DB::table('user_preferences')->where('pref_key', 'task_card_color')->where('device_id', '')->where('pref_value', '!=', '')->get(['user_id', 'pref_value']) as $row) {
            $personalColorByUser[(int) $row->user_id] = $row->pref_value;
        }
        $resolveColor = fn (int $userId) => $colorByOwner[$userId] ?? $personalColorByUser[$userId] ?? null;

        if ($visibility->visibleOwnerIds($viewerUserId, $subInstituteId) === null) {
            // Privileged — every active user in the tenant is a valid feed,
            // most with no actual share row at all; $colorByOwner still
            // applies to whichever few owners DID share with them specifically.
            return DB::table('tbluser')
                ->where('sub_institute_id', $subInstituteId)->where('status', 1)->whereNull('deleted_at')
                ->get(['id', DB::raw("TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name")])
                ->map(fn ($row) => ['user_id' => (string) $row->id, 'name' => (string) $row->name, 'color' => $resolveColor((int) $row->id)])
                ->all();
        }

        $sharedOwnerIds = $shareRows->pluck('owner_user_id')->map(fn ($id) => (int) $id)->unique()->all();

        $subordinateIds = DB::table('tbluser')
            ->where('employee_id', $viewerUserId)->where('sub_institute_id', $subInstituteId)->where('status', 1)
            ->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ids = array_unique([$viewerUserId, ...$sharedOwnerIds, ...$subordinateIds]);

        return DB::table('tbluser')->whereIn('id', $ids)
            ->get(['id', DB::raw("TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) as name")])
            ->map(fn ($row) => ['user_id' => (string) $row->id, 'name' => (string) $row->name, 'color' => $resolveColor((int) $row->id)])
            ->all();
    }
}
