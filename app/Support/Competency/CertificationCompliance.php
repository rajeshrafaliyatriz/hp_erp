<?php

namespace App\Support\Competency;

/**
 * Whether a certification is compliant, and how it is described.
 *
 * ── WHY THIS IS NOT A PRIVATE METHOD ON A CONTROLLER ────────────────────────
 *
 * It was. `CertificationController` computed it for the HR screen, and when the
 * employee's own "My Certifications" page was added it needed the same answer.
 * Two copies of an ordered ladder over dates is two answers: HR sees a
 * credential as Expiring Soon on day 60 and the employee sees Compliant,
 * because one copy used `<` and the other `<=`. Nobody would notice, because
 * the two screens are never open side by side.
 *
 * That is the same failure the hiring poster had - the PDF and the PNG were
 * drawn by different renderers and would eventually have advertised different
 * requirements for one job - and the fix is the same: decide once, render
 * twice. Every caller here gets the identical verdict for the identical row.
 *
 * ── WHY A CLASS AND NOT A TRAIT ─────────────────────────────────────────────
 *
 * Constants in traits need PHP 8.2. This repo requires ^8.2 and runs 8.2 here,
 * but the window below is the number the whole module's compliance turns on and
 * it is not worth making it depend on a production PHP version nobody has
 * checked. A class constant works everywhere.
 */
final class CertificationCompliance
{
    /**
     * How long before expiry a credential starts reading as "Expiring Soon".
     *
     * Referenced by the list's compliance ladder, the Expiring Soon tab, the
     * expiry_window filter options and the employee's own page - so changing it
     * here changes all of them together, which is the point.
     */
    public const EXPIRING_WINDOW_DAYS = 60;

    public const STATUS_LABELS = [
        'valid'    => 'Active',
        'expiring' => 'Expiring',
        'expired'  => 'Expired',
        'revoked'  => 'Revoked',
    ];

    /**
     * The compliance verdict for one row.
     *
     * An ordered ladder, and the order carries the meaning: a revoked
     * credential is non-compliant even if its expiry date is years away, and an
     * expired one is non-compliant even if its stored status still says valid.
     * The stored status and the date can disagree - a nightly job is what moves
     * `status` to expired - so the date is trusted over the column.
     *
     * @param  object  $row  Needs `status` and `expiry_date`.
     * @return array{key:string, label:string, reason:string}
     */
    public static function state($row): array
    {
        $expiry = $row->expiry_date ? strtotime((string) $row->expiry_date) : null;
        $today = strtotime(now()->toDateString());

        if ($row->status === 'revoked') {
            return ['key' => 'non_compliant', 'label' => 'Non-Compliant', 'reason' => 'Credential has been revoked'];
        }
        if ($row->status === 'expired' || ($expiry !== null && $expiry < $today)) {
            return ['key' => 'non_compliant', 'label' => 'Non-Compliant', 'reason' => 'Credential has expired'];
        }
        if ($expiry !== null && $expiry <= strtotime('+' . self::EXPIRING_WINDOW_DAYS . ' days', $today)) {
            return [
                'key'    => 'expiring',
                'label'  => 'Expiring Soon',
                'reason' => 'Expires within ' . self::EXPIRING_WINDOW_DAYS . ' days',
            ];
        }

        return ['key' => 'compliant', 'label' => 'Compliant', 'reason' => 'Credential is valid'];
    }

    /**
     * Whole days until expiry - negative once past, null when open-ended.
     *
     * Null and 0 mean different things and the caller must not conflate them:
     * null is "this credential does not expire", 0 is "it expires today".
     */
    public static function daysToExpiry($expiryDate): ?int
    {
        if (!$expiryDate) {
            return null;
        }

        $today = strtotime(now()->toDateString());

        return (int) floor((strtotime((string) $expiryDate) - $today) / 86400);
    }

    public static function statusLabel($status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst((string) $status);
    }

    public static function humanDate($value): ?string
    {
        return $value ? date('d M Y', strtotime((string) $value)) : null;
    }
}
