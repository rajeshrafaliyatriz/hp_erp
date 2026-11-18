<?php

namespace App\Domain\Crm\Sales;

use Illuminate\Support\Facades\DB;

/**
 * The one writer of crm_opportunity_stage_history for the paths that change an opportunity (slice 11). Before this, the opportunity controller kept its own
 * private copy and the paths that did not go through it (the CSV import, a signal promoted to an opportunity, the GTM deal sync) wrote no row at all, so the
 * history could not explain a forecast change. Every path that sets a stage now calls this.
 *
 * A row records the stage the opportunity left, the stage it entered, and the amount, probability and closing date it had AFTER the change, so a later reader
 * can replay a deal's life. A row is written when any of those four values changed, not only the stage (a deal pushed to next quarter is a change worth
 * knowing about), and the comparison is by value: "50000" and "50000.00" are the same amount, "2026-12-03" and "2026-12-03 00:00:00" the same date, so saving a
 * form without touching anything adds nothing.
 */
final class OpportunityStageHistory
{
    /** @var list<string> */
    public const TRACKED = ['sales_stage', 'amount', 'probability', 'closing_date'];

    /** Writes one row, unconditionally. $userId is the person who made the change (null for the system). */
    public static function record(int $opportunityId, ?string $fromStage, ?string $toStage, $amount, $probability, $closingDate, ?int $userId): void
    {
        DB::table('crm_opportunity_stage_history')->insert([
            'opportunity_id' => $opportunityId,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'amount' => $amount,
            'probability' => $probability,
            'closing_date' => $closingDate,
            'changed_by' => $userId ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Whether $newData (column => value, the columns about to be written) changes any tracked value of $existing. A column that is not in $newData is not
     * being changed.
     *
     * @param  array<string, mixed>  $newData
     */
    public static function changed(object $existing, array $newData): bool
    {
        foreach (self::TRACKED as $column) {
            if (array_key_exists($column, $newData) && ! self::same($column, $newData[$column], $existing->{$column} ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writes a row when $newData changes a tracked value of $existing, with the values the opportunity will have after the change. Returns whether it wrote.
     *
     * @param  array<string, mixed>  $newData
     */
    public static function recordIfChanged(object $existing, array $newData, ?int $userId): bool
    {
        if (! self::changed($existing, $newData)) {
            return false;
        }

        self::record(
            (int) $existing->id,
            $existing->sales_stage ?? null,
            array_key_exists('sales_stage', $newData) ? $newData['sales_stage'] : ($existing->sales_stage ?? null),
            array_key_exists('amount', $newData) ? $newData['amount'] : ($existing->amount ?? null),
            array_key_exists('probability', $newData) ? $newData['probability'] : ($existing->probability ?? null),
            array_key_exists('closing_date', $newData) ? $newData['closing_date'] : ($existing->closing_date ?? null),
            $userId,
        );

        return true;
    }

    /** @param mixed $new @param mixed $old */
    private static function same(string $column, $new, $old): bool
    {
        $new = $new === '' ? null : $new;
        $old = $old === '' ? null : $old;

        if ($new === null || $old === null) {
            return $new === $old;
        }

        return match ($column) {
            'amount', 'probability' => is_numeric($new) && is_numeric($old) ? abs((float) $new - (float) $old) < 0.005 : (string) $new === (string) $old,
            'closing_date' => substr((string) $new, 0, 10) === substr((string) $old, 0, 10),
            default => trim((string) $new) === trim((string) $old),
        };
    }
}
