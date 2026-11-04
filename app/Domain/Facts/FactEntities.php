<?php

namespace App\Domain\Facts;

use Illuminate\Support\Facades\DB;

/**
 * The three kinds of record a fact can be about (slice 4): a contact, an organisation, a lead. Finds a record for a caller's tenant, names it, and knows
 * which column of it a field is written to (config/facts.php `fields`).
 *
 * EVERY lookup carries the tenant and skips a removed record, so a record of another organisation, a removed one and one that never existed are the same
 * "not found": a caller learns nothing by guessing ids. (`crm_leads.sub_institute_id` is nullable on old rows; a row with no tenant is nobody's.)
 */
final class FactEntities
{
    public const TYPES = ['contact', 'organization', 'lead'];

    private const TABLES = ['contact' => 'crm_contacts', 'organization' => 'crm_organizations', 'lead' => 'crm_leads'];

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function table(string $type): string
    {
        return self::TABLES[$type];
    }

    public static function find(int $tenantId, string $type, int $id): ?object
    {
        if (! self::isType($type) || $tenantId <= 0 || $id <= 0) {
            return null;
        }

        return DB::table(self::TABLES[$type])->where('id', $id)->where('sub_institute_id', $tenantId)->whereNull('deleted_at')->first() ?: null;
    }

    /** The name a person knows the record by. */
    public static function name(string $type, object $row): string
    {
        if ($type === 'organization') {
            return (string) ($row->name ?? '');
        }
        $person = trim(((string) ($row->first_name ?? '')) . ' ' . ((string) ($row->last_name ?? '')));

        return $person !== '' ? $person : (string) ($row->company ?? '');
    }

    /**
     * Names for many records at once.
     *
     * @param  list<array{0: string, 1: int}>  $pairs  [type, id]
     * @return array<string, string> "type:id" => name
     */
    public static function names(int $tenantId, array $pairs): array
    {
        $byType = [];
        foreach ($pairs as [$type, $id]) {
            if (self::isType($type)) {
                $byType[$type][(int) $id] = true;
            }
        }
        $names = [];
        foreach ($byType as $type => $ids) {
            $columns = $type === 'organization' ? ['id', 'name'] : ['id', 'first_name', 'last_name', ...($type === 'lead' ? ['company'] : [])];
            foreach (DB::table(self::TABLES[$type])->where('sub_institute_id', $tenantId)->whereIn('id', array_keys($ids))->get($columns) as $row) {
                $names["{$type}:{$row->id}"] = self::name($type, $row);
            }
        }

        return $names;
    }

    /** The column an accepted fact of this field is written to on this kind of record; null for a field kept in the ledger only (or a field that does not apply). */
    public static function targetColumn(string $type, string $field): ?string
    {
        $column = config("facts.fields.{$field}.targets.{$type}");

        return is_string($column) && $column !== '' ? $column : null;
    }

    /** Whether a field can be a fact of this kind of record (an organisation has no job title). */
    public static function applies(string $type, string $field): bool
    {
        $targets = config("facts.fields.{$field}.targets");

        return is_array($targets) && array_key_exists($type, $targets);
    }
}
