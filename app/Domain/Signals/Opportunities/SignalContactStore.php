<?php

namespace App\Domain\Signals\Opportunities;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and writes the contacts found for an organisation (g2g_signal_contacts). Always tenant-scoped. Tolerates the table not existing yet
 * (the migration not run): it then reads as "no contacts" and writes nothing, so a signal is displayed exactly as before.
 */
final class SignalContactStore
{
    private const TABLE = 'g2g_signal_contacts';
    private const FIELDS = ['full_name', 'designation', 'email', 'phone', 'linkedin_url', 'website', 'location', 'source_url', 'email_source_url', 'phone_source_url', 'role_searched'];

    public static function ready(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  list<array<string, mixed>>  $contacts  @return int how many contacts were added or completed */
    public static function save(int $tenantId, int $companyId, ?int $opportunityId, array $contacts): int
    {
        if (! self::ready()) {
            return 0;
        }
        $changed = 0;
        $existing = DB::table(self::TABLE)->where('sub_institute_id', $tenantId)->where('company_id', $companyId)->get();
        foreach ($contacts as $c) {
            $row = [];
            foreach (self::FIELDS as $f) {
                $v = isset($c[$f]) ? trim((string) $c[$f]) : '';
                $row[$f] = $v === '' ? null : mb_substr($v, 0, in_array($f, ['linkedin_url', 'website', 'source_url', 'email_source_url', 'phone_source_url'], true) ? 490 : 190);
            }
            // Only values that pass the same checks as everywhere else are ever stored.
            if ($row['email'] !== null && ! SignalContact::validEmail($row['email'])) {
                $row['email'] = $row['email_source_url'] = null;
            }
            if ($row['phone'] !== null && ($n = SignalContact::normalisePhone($row['phone'])) === null) {
                $row['phone'] = $row['phone_source_url'] = null;
            } elseif ($row['phone'] !== null) {
                $row['phone'] = $n;
            }
            if ($row['email'] === null && $row['phone'] === null && $row['full_name'] === null && $row['linkedin_url'] === null) {
                continue;
            }
            $match = $existing->first(fn ($e) => ($row['email'] !== null && strtolower((string) $e->email) === strtolower($row['email']))
                || ($row['phone'] !== null && (string) $e->phone === $row['phone'])
                || ($row['linkedin_url'] !== null && strtolower((string) $e->linkedin_url) === strtolower($row['linkedin_url']))
                || ($row['full_name'] !== null && strtolower((string) $e->full_name) === strtolower($row['full_name'])));
            $now = now();
            if ($match) {
                $fill = [];
                foreach ($row as $f => $v) {
                    if ($v !== null && ($match->{$f} === null || $match->{$f} === '')) {
                        $fill[$f] = $v;
                    }
                }
                if ($fill !== []) {
                    DB::table(self::TABLE)->where('id', $match->id)->where('sub_institute_id', $tenantId)->update($fill + ['researched_at' => $now, 'updated_at' => $now]);
                    $changed++;
                }

                continue;
            }
            $id = DB::table(self::TABLE)->insertGetId($row + [
                'sub_institute_id' => $tenantId, 'company_id' => $companyId, 'opportunity_id' => $opportunityId,
                'verification_status' => 'unverified', 'researched_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $existing->push((object) ($row + ['id' => $id]));
            $changed++;
        }

        return $changed;
    }

    /** @param  list<int>  $companyIds  @return array<int, list<array<string, mixed>>> contacts per company, most complete first */
    public static function forCompanies(int $tenantId, array $companyIds): array
    {
        if ($companyIds === [] || ! self::ready()) {
            return [];
        }
        $out = [];
        foreach (DB::table(self::TABLE)->where('sub_institute_id', $tenantId)->whereIn('company_id', $companyIds)->orderBy('id')->get() as $r) {
            $out[(int) $r->company_id][] = (array) $r;
        }
        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => self::score($b) <=> self::score($a));
        }

        return $out;
    }

    private static function score(array $c): int
    {
        return ($c['email'] ? 4 : 0) + ($c['phone'] ? 4 : 0) + ($c['full_name'] ? 2 : 0) + ($c['designation'] ? 1 : 0) + ($c['linkedin_url'] ? 1 : 0);
    }

    // The "already searched" marker keeps one signal's refresh from spending search credits again for a company searched recently.
    private static function key(int $tenantId, int $companyId): string
    {
        return "signals:contact-research:{$tenantId}:{$companyId}";
    }

    public static function markSearched(int $tenantId, int $companyId, string $outcome): void
    {
        try {
            Cache::put(self::key($tenantId, $companyId), ['at' => now()->toIso8601String(), 'outcome' => $outcome], now()->addDays(max(1, (int) config('signals_connectors.contact_research.recheck_days', 7))));
        } catch (\Throwable) {
        }
    }

    /** @return ?array{at: string, outcome: string} */
    public static function lastSearch(int $tenantId, int $companyId): ?array
    {
        try {
            $v = Cache::get(self::key($tenantId, $companyId));

            return is_array($v) ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
