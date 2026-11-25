<?php

namespace App\Domain\Crm\Contacts;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `include=people` (S18, contract section 2, "Company-wide activity"): a company's e-mails, documents, comments, history, schedule and quotes can also return the rows of the live people of
 * that company, each row tagged with `personId` and `personName` (null for a company row). `include=company` is the default and changes nothing.
 *
 * The people of the company are read in ONE query per request (id and name of every live contact of the company, tenant scoped); the callers build their WHERE ... IN from it and tag the rows
 * from the same map, so the number of queries does not depend on the number of people.
 *
 * Rule for every endpoint: `include=people` only ADDS rows. A row that is linked to the company itself keeps personId = null even when it is also linked to one of the people, so the rows
 * of the default answer are all still there, unchanged, and every extra row names the person it belongs to.
 */
final class CompanyPeople
{
    /** The include list is comma separated ("people", "company,people"); only the word people turns anything on. */
    public static function wanted(Request $request): bool
    {
        $include = $request->input('include');
        if (! is_string($include)) {
            return false;
        }

        return in_array('people', array_map('trim', explode(',', strtolower($include))), true);
    }

    /**
     * The live people of one company of this tenant: contact id => display name. ONE query.
     *
     * @return array<int, string>
     */
    public static function of(int $tenantId, int $organizationId): array
    {
        $people = [];
        foreach (DB::table('crm_contacts')->where('sub_institute_id', $tenantId)->where('organization_id', $organizationId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'first_name', 'last_name']) as $row) {
            $people[(int) $row->id] = self::name($row->first_name, $row->last_name);
        }

        return $people;
    }

    /** "First Last" as SQL, for a LIKE over the full name; the alias is a table alias of crm_contacts. MariaDB/MySQL have CONCAT, the sqlite test database only ||. */
    public static function fullNameSql(string $alias): string
    {
        $alias = preg_replace('/[^a-z0-9_]/i', '', $alias);

        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT(COALESCE({$alias}.first_name, ''), ' ', {$alias}.last_name)"
            : "(COALESCE({$alias}.first_name, '') || ' ' || {$alias}.last_name)";
    }

    public static function name(?string $first, ?string $last): string
    {
        return trim(trim((string) $first) . ' ' . trim((string) $last));
    }

    /**
     * The tag of one row: a row linked to a person of the company (type CONTACT, an id in $people) names that person; anything else is a company row.
     *
     * @param  array<int, string>  $people
     * @return array{personId: ?string, personName: ?string}
     */
    public static function tag(array $people, ?string $linkedType, int|string|null $linkedId): array
    {
        if ($linkedType !== null && strtoupper($linkedType) === 'CONTACT' && $linkedId !== null && isset($people[(int) $linkedId])) {
            return ['personId' => (string) (int) $linkedId, 'personName' => $people[(int) $linkedId]];
        }

        return ['personId' => null, 'personName' => null];
    }

    /**
     * One row per id: a row that is linked both to the company and to a person is listed once, as the company's row.
     *
     * @param  list<array<string, mixed>>  $items  each with `id` and `personId`
     * @return list<array<string, mixed>>
     */
    public static function onePerRecord(array $items): array
    {
        $out = [];
        $at = [];
        foreach ($items as $item) {
            $id = (string) $item['id'];
            if (! isset($at[$id])) {
                $at[$id] = count($out);
                $out[] = $item;
            } elseif ($out[$at[$id]]['personId'] !== null && $item['personId'] === null) {
                $out[$at[$id]] = $item;
            }
        }

        return $out;
    }
}
