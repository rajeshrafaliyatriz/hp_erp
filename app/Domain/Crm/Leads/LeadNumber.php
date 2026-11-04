<?php

namespace App\Domain\Crm\Leads;

use App\Domain\Numbering\NumberSequence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one place a lead number is made (slice 6, defect D8). The FORMAT is what it always was: `LD-` and five digits (`LD-00042`; a sixth digit simply
 * appears at 100,000).
 *
 * ONE counter for every organisation, key `lead:all`, on purpose: crm_leads.lead_no carries a UNIQUE index across ALL organisations (verified on dev:
 * `crm_leads_lead_no_unique`), so a counter per organisation would hand the second organisation's first lead the number of the first one's and the insert
 * would fail. (The note in the old nextLeadNo said the same.) If that index is ever made per organisation, only KEY below changes.
 *
 * The first claim seeds the counter from the highest number already in the table (soft-deleted rows count: their numbers are still taken). The claim is
 * then checked against the table, so a number somebody else made without asking the counter (an older server during a rollout, a script, a hand edit) is
 * stepped over instead of failing the insert: the counter is moved past everything that exists and the claim is made again.
 *
 * A host that has not run the migration for g2g_number_sequences yet keeps the old "highest plus one" so lead creation never stops working there.
 */
final class LeadNumber
{
    public const KEY = 'lead:all';

    private const ATTEMPTS = 3;

    public static function claim(): string
    {
        try {
            for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
                $number = self::format(NumberSequence::claim(self::KEY, fn () => self::highest() + 1));
                if (! DB::table('crm_leads')->where('lead_no', $number)->exists()) {
                    return $number;
                }
                NumberSequence::raiseTo(self::KEY, self::highest() + 1);
            }
        } catch (QueryException $e) {
            // The table is tried, not asked about: a check for it would cost a query on every lead made, and the answer is "there" everywhere but a host
            // that has not run the migration yet. Only "the counter's table is not there" falls back; any other database error is a real error.
            if (! self::isMissingCounterTable($e)) {
                throw $e;
            }

            return self::format(self::highest() + 1);
        }

        throw new \RuntimeException('A free lead number could not be found.');
    }

    private static function isMissingCounterTable(QueryException $e): bool
    {
        return str_contains($e->getMessage(), NumberSequence::TABLE) && ((string) $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist"));
    }

    public static function format(int $n): string
    {
        return 'LD-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    /** The highest number in use: the digits after the last dash of any lead_no, numerically (LD-100000 is higher than LD-99999). 0 when there is none. */
    public static function highest(): int
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return (int) DB::table('crm_leads')->max(DB::raw("CAST(SUBSTRING_INDEX(lead_no, '-', -1) AS UNSIGNED)"));
        }

        // Other drivers (the test database) have no SUBSTRING_INDEX: the same rule in PHP.
        $max = 0;
        foreach (DB::table('crm_leads')->whereNotNull('lead_no')->pluck('lead_no') as $number) {
            $number = (string) $number;
            $dash = strrpos($number, '-');
            $max = max($max, (int) ($dash === false ? $number : substr($number, $dash + 1)));
        }

        return $max;
    }
}
