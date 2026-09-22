<?php

namespace App\Services\Account;

use App\Support\RoleKey;
use Illuminate\Support\Facades\DB;

/**
 * WHO MAY SEE SOMEBODY'S MOBILE NUMBER, DATE OF BIRTH AND HOME ADDRESS.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS HAS TO BE ON THE SERVER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * An HR product holds a personal mobile number, a date of birth and a home
 * address, and until now every colleague who could open the Employee Directory
 * could read all three. That was never a setting anybody chose; it was the
 * absence of one.
 *
 * A visibility control that only hides fields in React is not a privacy feature -
 * it is a privacy-shaped decoration, and the data is still one `curl` away. So the
 * redaction happens where the rows are assembled, and the evidence for it reads
 * the API response rather than the screen.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * HR AND ADMINISTRATORS ARE NOT "COLLEAGUES"
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * They own the record: they typed the address in, they process the payroll that
 * needs it, and the edit form must round-trip what it loaded or saving blanks the
 * field. Hiding data from the people responsible for it would break the directory
 * rather than protect anybody - so visibility governs what OTHER EMPLOYEES see.
 *
 * The person themselves always sees their own details, for the same reason.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ONE QUERY, NOT ONE PER ROW
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `EmployeeDirectoryController::index()` returns up to `MAX_ROWS` = 2000 people.
 * Reading each owner's preferences individually would be 2000 queries on one page
 * load, which would make the directory slower than the privacy is worth.
 * `forUsers()` fetches every relevant preference for the whole page in a single
 * statement, keyed by user id.
 */
class ProfileVisibility
{
    /** Response field => the preference that governs it. */
    public const GOVERNED = [
        'mobile' => 'visible_mobile',
        'birthdate' => 'visible_birthdate',
        'address' => 'visible_address',
        'address_2' => 'visible_address',
        'pincode' => 'visible_address',
        'city' => 'visible_address',
        'state' => 'visible_address',
    ];

    /**
     * Visibility settings for a set of people, in one query.
     *
     * Returns `[userId => ['visible_mobile' => 'everyone', ...]]`, filled in from
     * `UserPreferences::DEFAULTS` for anybody who has never chosen - which is
     * everybody, on the day this ships.
     */
    public function forUsers(array $userIds): array
    {
        $defaults = [];

        foreach (UserPreferences::VISIBILITY_KEYS as $key) {
            $defaults[$key] = UserPreferences::DEFAULTS[$key];
        }

        $byUser = [];

        foreach ($userIds as $id) {
            $byUser[(int) $id] = $defaults;
        }

        if (!$userIds) {
            return $byUser;
        }

        /*
         * ACCOUNT SCOPE ONLY. Visibility is a property of the person, not of the
         * laptop they happened to set it on, so `device_id = ''` is the only row
         * that counts - see UserPreferences::ACCOUNT_SCOPE. Reading a device row
         * here would mean somebody's privacy changed depending on which machine
         * a COLLEAGUE was using, which is nonsense.
         */
        $rows = DB::table('user_preferences')
            ->whereIn('user_id', $userIds)
            ->where('device_id', UserPreferences::ACCOUNT_SCOPE)
            ->whereIn('pref_key', UserPreferences::VISIBILITY_KEYS)
            ->get(['user_id', 'pref_key', 'pref_value']);

        foreach ($rows as $row) {
            $value = (string) $row->pref_value;

            // An unrecognised value must never mean "show it". A typo, a partial
            // write or an older client's guess falls back to the default rather
            // than opening the field up.
            if (!in_array($value, UserPreferences::VISIBILITY, true)) {
                continue;
            }

            $byUser[(int) $row->user_id][$row->pref_key] = $value;
        }

        return $byUser;
    }

    /**
     * Whether `$viewer` may see `$field` on `$owner`.
     *
     * `$owner` and `$viewer` need `id` and `department_id`. `$viewerRole` is the
     * viewer's role_key, resolved once by the caller rather than per row.
     */
    public function maySee(string $field, object $owner, object $viewer, ?string $viewerRole, array $visibility): bool
    {
        $governedBy = self::GOVERNED[$field] ?? null;

        // A field nobody chose to govern is visible, as it always was.
        if (!$governedBy) {
            return true;
        }

        // Your own record, always.
        if ((int) ($owner->id ?? 0) === (int) ($viewer->id ?? 0)) {
            return true;
        }

        // The people who maintain the record. See the class note.
        if (in_array($viewerRole, ['administrator', 'hr_manager', 'hr_executive'], true)) {
            return true;
        }

        $setting = $visibility[$governedBy] ?? UserPreferences::DEFAULTS[$governedBy];

        if ($setting === 'private') {
            return false;
        }

        if ($setting === 'department') {
            $ownerDept = (int) ($owner->department_id ?? 0);
            $viewerDept = (int) ($viewer->department_id ?? 0);

            /*
             * A person with no department is not "in the same department" as
             * another person with no department. Treating 0 as a match would put
             * everybody whose record is incomplete into one shared group, which
             * is the opposite of what "my department" means.
             */
            return $ownerDept > 0 && $ownerDept === $viewerDept;
        }

        return true;
    }

    /**
     * Blank the fields `$viewer` may not see, across a whole result set.
     *
     * Blanked rather than removed: the frontend and the edit form expect a stable
     * shape, and a missing key reads as "not loaded yet" where an empty one reads
     * as "nothing to show". Removing them would turn a privacy setting into a
     * series of undefined-property bugs on screens nobody changed.
     */
    public function redact(iterable $rows, object $viewer, ?string $viewerRole = null): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);

        if (!$rows) {
            return [];
        }

        $viewerRole = $viewerRole ?? RoleKey::forUserId((int) ($viewer->id ?? 0));

        $ids = [];

        foreach ($rows as $row) {
            if (isset($row->id)) {
                $ids[] = (int) $row->id;
            }
        }

        $visibility = $this->forUsers($ids);

        foreach ($rows as $row) {
            $owned = $visibility[(int) ($row->id ?? 0)] ?? [];

            foreach (array_keys(self::GOVERNED) as $field) {
                if (!property_exists($row, $field)) {
                    continue;
                }

                if (!$this->maySee($field, $row, $viewer, $viewerRole, $owned)) {
                    $row->{$field} = null;
                }
            }
        }

        return $rows;
    }
}
