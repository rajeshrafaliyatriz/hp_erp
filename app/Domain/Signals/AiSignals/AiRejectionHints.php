<?php

namespace App\Domain\Signals\AiSignals;

/**
 * What to change in the scheduled task, in plain words, for each reason the quality gate can reject a delivered signal. The gate's own `reason` says what
 * is wrong with this record; this says how to fix the task so the next delivery passes. Static text: nothing here reads data or calls anything.
 */
final class AiRejectionHints
{
    private const HINTS = [
        'invalid_record' => 'Each signal must be a JSON object, not text or a number.',
        'missing_buyer' => 'Give buyer.name: the organisation that would buy. A signal with no organisation is a rumour.',
        'missing_trigger' => 'Give trigger_summary: one sentence naming the specific event that could cause a purchase.',
        'missing_date' => 'Give event_date as YYYY-MM-DD (or observed_at). Do not leave the date out.',
        'invalid_date' => 'Write dates as YYYY-MM-DD, for example 2026-11-24. Do not write "yesterday", "last Tuesday", "24/11/2026" or "Nov 24".',
        'missing_source_url' => 'Give source_url: the real http(s) link the task read. A signal without a source link cannot be checked.',
        'invalid_claim_level' => 'claim_level must be exactly confirmed, inference or hypothesis. Use "confirmed" only for something read on the source page.',
        'invalid_fetch_level' => 'fetch_level must be exactly full_document, page_text, search_snippet or blocked.',
        'invalid_trigger_type' => 'trigger_type must be one of: tender, rfp, eoi, rfq, programme, regulation, expansion, funding, acquisition, leadership, hiring, partnership, vendor_search, pain_point, other. Or leave it out.',
        'invalid_buyer_type' => 'buyer.type must be one of: government, institutional, sme, school, enterprise, other, school_group, college, university, public_sector, hospital, training_institute. Or leave it out.',
        'invalid_business_fit' => 'business_fit must be one of: eb, scholar, g2g, multiple. Or leave it out.',
        'invalid_entry_point' => 'entry_point must be one of: discovery_meeting, pilot, poc, paid_assessment, workshop, si_partnership, rfp_response, other. Or leave it out.',
        'invalid_scale' => 'scale must be one of: small, medium, large, strategic. Or leave it out.',
        'invalid_context' => 'context must be market or competitor. Or leave it out.',
        'entry_point_other_needs_reason' => 'If entry_point is "other", also give entry_point_reason (at least 10 characters). Or choose a specific entry point.',
        'vague_sell_line' => 'what_we_could_sell must name a concrete deliverable, scope and buyer unit, not just "ERP". Or leave it out.',
        'invalid_need_code' => 'candidate_need_codes must be codes N01 to N20. Or leave it out.',
        'invalid_scores' => 'If you send scores, send all seven (buying_signal, problem_fit, product_fit, accessibility, urgency, potential_value, evidence_quality), each a whole number 1 to 5. Or leave scores out.',
        'missing_scores' => 'Send all seven scores, each a whole number 1 to 5.',
        'expired_tender' => 'The closing date has already passed, so this is no longer an opportunity. Leave closed tenders out of the feed.',
        'outside_icp' => 'This organisation is outside the ideal customer profile. Leave it out of the feed.',
        'below_fit_floor' => 'The product fit is below the minimum G2G accepts for the feed.',
        'below_intent_floor' => 'The buying intent is below the minimum G2G accepts for the feed.',
        'below_score_bar' => 'The scores are below the minimum G2G accepts for the feed.',
        'incomplete_card' => 'The signal is missing text the feed card needs. Add the fields named in the reason.',
    ];

    public static function for(?string $code): ?string
    {
        return $code === null ? null : (self::HINTS[$code] ?? null);
    }
}
