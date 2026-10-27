<?php

namespace App\Domain\Signals\Products;

/**
 * A PROPOSED, sharper ScholarClone ICP. It is prepared here and NOT applied: nothing reads this class when signals are generated, it is not saved to
 * any database, and the built-in definition (config/signals_icp.php, `scholar_k12`) is unchanged. A person applies it through the ICP editor
 * (Product Portfolio > ScholarClone > Ideal customer), where it is validated by the server and saved as a new version.
 *
 * It changes ONLY settings the editor manages (guidance, triggers, queries, exclusions) and only for ScholarClone. Everything that decides how a
 * lead is qualified or scored stays exactly as the built-in definition has it: must-have rules, criteria, evidence points and rules, priority
 * bands, freshness (`decay`, `decay_by_trigger_type`), `min_known_share`, per-run limits, parts and the product line. Evidence confidence is a
 * separate thing (the server's evidence class and quote check), and nothing here lowers it.
 *
 * Every phrase below is a search/matching word, not a claim: no customer, quote, source or buying intent is stated. A trigger only counts when a
 * dated source names the school, and each part named is one ScholarClone already sells (school_erp, lms_pal, teacher_competency,
 * career_intelligence, intelligence_layer).
 */
final class ScholarCloneIcpProposal
{
    public const TEMPLATE = 'scholar_k12';

    /** Triggers that are a direct buying statement or a formal purchase. */
    public const STRONG_TRIGGERS = ['tender', 'erp_replacement', 'system_change', 'vendor_search', 'multi_campus_system', 'digital_transformation'];

    /** Triggers that are promising but are not a purchase on their own: a person decides. They are kept, and the guidance says so. */
    public const WEAK_TRIGGERS = ['new_campus', 'leadership', 'academic', 'staff_development', 'career', 'pain_point'];

    /**
     * The built-in definition with the proposed settings laid over it. Pure: the configuration is read, never changed.
     *
     * @param  array<string, mixed>|null  $template  the current definition (defaults to the configured one)
     * @return array<string, mixed>
     */
    public static function build(?array $template = null): array
    {
        $current = $template ?? (array) config('signals_icp.' . self::TEMPLATE);
        $proposal = $current;

        $proposal['guidance'] = 'Buy-intent first. STRONG: a school ERP tender, RFP or EOI; replacing or switching its ERP; a new campus or group naming a system it needs; an announced admissions, fee or school-management upgrade; a confirmed digital-transformation plan. WEAK, never enough alone: awards, results, events, admissions ads, a new principal, training. The source must name the school and be dated.';

        $triggers = (array) $current['triggers'];
        // Existing triggers keep their key, source, part and freshness; only their buying words are made more explicit.
        $triggers['tender']['phrases'] = array_values(array_unique([...(array) $triggers['tender']['phrases'], 'school ERP', 'RFP', 'EOI', 'expression of interest', 'request for proposal']));
        $triggers['system_change']['phrases'] = array_values(array_unique([...(array) $triggers['system_change']['phrases'], 'ERP migration', 'new school software']));
        $triggers['new_campus']['pain'] = 'a new campus needs systems from day one; counts only when a system need is stated';

        $triggers['erp_replacement'] = [
            'source' => 'news', 'part' => 'school_erp', 'decay' => 60,
            'pain' => 'the school says it is replacing or switching its current school ERP',
            'phrases' => ['replacing our ERP', 'replace the ERP', 'switching ERP', 'changing school ERP', 'ERP replacement', 'moving to a new ERP', 'current ERP not working'],
        ];
        $triggers['multi_campus_system'] = [
            'source' => 'news', 'part' => 'intelligence_layer', 'decay' => 90,
            'pain' => 'a school group wants all campuses on one system and one view',
            'phrases' => ['all campuses on one', 'single system across campuses', 'centralised ERP', 'unified school platform', 'common platform for all branches'],
        ];
        $triggers['digital_transformation'] = [
            'source' => 'news', 'part' => 'school_erp', 'decay' => 90,
            'pain' => 'the school announced a digital-transformation or system-consolidation plan',
            'phrases' => ['digital transformation', 'digitising school operations', 'going paperless', 'consolidating systems', 'automating admissions and fees'],
        ];
        $proposal['triggers'] = $triggers;

        $tenderDomains = [];
        foreach ((array) $current['queries'] as $q) {
            if (($q['id'] ?? '') === 'k12-tender-sms') {
                $tenderDomains = (array) ($q['domains'] ?? []);
            }
        }
        $queries = (array) $current['queries'];
        $queries[] = ['id' => 'k12-tender-rfp-erp', 'source' => 'tender', 'topic' => 'general', 'days' => 90, ...($tenderDomains === [] ? [] : ['domains' => $tenderDomains]), 'q' => 'RFP OR EOI OR tender "school ERP" OR "school management software" OR "student information system"'];
        $queries[] = ['id' => 'k12-news-erp-replace', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'school "replacing" OR "switching" OR "migrating" ERP OR "school management software" Gujarat OR Maharashtra'];
        $queries[] = ['id' => 'k12-news-consolidate', 'source' => 'news', 'topic' => 'news', 'days' => 45, 'q' => 'school group "all campuses" OR "digital transformation" OR "centralised" system admissions fees Gujarat OR Maharashtra'];
        $proposal['queries'] = $queries;

        // More exclusions (name-matched, whole phrases). They agree with the built-in must-have rule that the school is private.
        $proposal['disqualifiers'] = [
            ...(array) $current['disqualifiers'],
            ['label' => 'government, aided or municipal school', 'phrases' => ['government school', 'govt school', 'aided school', 'grant-in-aid', 'municipal school', 'zilla parishad', 'kendriya vidyalaya', 'navodaya']],
        ];

        return $proposal;
    }

    /** The settings the proposal changes, for a before/after look. @return list<string> */
    public static function changedSettings(?array $template = null): array
    {
        $current = $template ?? (array) config('signals_icp.' . self::TEMPLATE);
        $new = self::build($current);

        return array_values(array_filter(array_keys($new), fn ($k) => ($current[$k] ?? null) !== $new[$k]));
    }
}
