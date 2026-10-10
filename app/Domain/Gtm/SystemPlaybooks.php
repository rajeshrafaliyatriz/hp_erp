<?php

namespace App\Domain\Gtm;

/**
 * Platform-default GTM playbooks (gtm_playbooks.sub_institute_id = NULL).
 *
 * These are METHOD, not data: instructions a model follows and rubrics a deal is scored
 * against. They contain no company, person, figure or example result. Every playbook tells
 * the model to use only the records it is given and to say what is missing, which is what
 * keeps the AI agents built on them from inventing facts. The wording is original to G2G;
 * MEDDICC and BANT are named because they are public qualification frameworks, not because
 * any third party's text is reproduced.
 *
 * A tenant customises a default by copying it (same slug, its own row). The copy overrides
 * the default for that tenant only; the default itself is never edited through the API.
 */
final class SystemPlaybooks
{
    private const GROUND = ' Use ONLY the records supplied in the input. Never invent a fact, figure, person or event. '
        .'If something you need is absent, list it under "missing" instead of guessing, and cite the record or source URL behind every claim.';

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'kind' => 'role_playbook', 'role' => 'sdr', 'stage' => 'prospecting', 'slug' => 'prospecting-account-research',
                'title' => 'Account research brief',
                'description' => 'Turn the research signals already collected for an account into a short brief a rep can act on.',
                'body' => 'You are preparing a sales rep to approach one target account. Summarise why this account may need the seller\'s product now, '
                    .'using the research signals provided (each has a title, event and source URL). Rank the signals by how strongly they indicate need, '
                    .'name the buying trigger, suggest which role to approach first, and propose one concrete next step.'.self::GROUND,
                'inputs' => ['account', 'product_profile', 'signals'],
                'output_schema' => ['summary' => 'string', 'trigger' => 'string', 'ranked_signals' => '[{signal_id, why}]', 'target_roles' => '[string]', 'next_step' => 'string', 'missing' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'sdr', 'stage' => 'prospecting', 'slug' => 'prospecting-signal-prioritisation',
                'title' => 'Signal prioritisation',
                'description' => 'Decide which unreviewed buying signals deserve outreach first.',
                'body' => 'Given the unreviewed buying signals provided, order them by commercial urgency: recency of the event, strength of evidence, '
                    .'fit with the product profile, and whether a named company is involved. Flag any signal whose source is weak or whose event date is unknown.'.self::GROUND,
                'inputs' => ['product_profile', 'signals'],
                'output_schema' => ['ordered' => '[{signal_id, reason}]', 'weak_evidence' => '[signal_id]', 'missing' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'sdr', 'stage' => 'outreach', 'slug' => 'outreach-first-touch-email',
                'title' => 'First-touch email',
                'description' => 'Draft a short first email that references a real, sourced buying signal. Always a draft; never sent without approval.',
                'body' => 'Write a first-touch email to the contact provided. Lead with the specific, sourced event that makes the message relevant, connect it to one problem '
                    .'the seller solves (from the product profile), and ask for one small next step. Plain language, no flattery, no unverifiable claims, under 120 words. '
                    .'Do not mention anything about the recipient that is not in the input. Output a subject and a body.'.self::GROUND,
                'inputs' => ['account', 'contact', 'product_profile', 'signals'],
                'output_schema' => ['subject' => 'string', 'body' => 'string', 'signal_used' => 'signal_id', 'claims' => '[{claim, source_url}]', 'missing' => '[string]'],
            ],
            [
                'kind' => 'workflow', 'role' => 'sdr', 'stage' => 'outreach', 'slug' => 'outreach-follow-up-sequence',
                'title' => 'Four-touch follow-up sequence',
                'description' => 'A multi-step sequence definition. Each step is drafted for approval; nothing is sent automatically.',
                'body' => 'Draft each step of the sequence for the contact provided. Every step must add something new (a different angle, a different signal, or a useful question) '
                    .'rather than repeat the first email. Stop the sequence when the contact replies, bounces or unsubscribes.'.self::GROUND,
                'inputs' => ['account', 'contact', 'product_profile', 'signals', 'previous_activities'],
                'output_schema' => ['steps' => '[{step, subject, body, angle}]', 'missing' => '[string]'],
                'definition' => ['steps' => [
                    ['step' => 1, 'channel' => 'email', 'wait_days' => 0, 'goal' => 'Introduce with a sourced trigger'],
                    ['step' => 2, 'channel' => 'email', 'wait_days' => 3, 'goal' => 'Add a second angle or a question'],
                    ['step' => 3, 'channel' => 'email', 'wait_days' => 5, 'goal' => 'Share something of value tied to the trigger'],
                    ['step' => 4, 'channel' => 'email', 'wait_days' => 7, 'goal' => 'Polite close: ask whether to stop'],
                ], 'requires_approval' => true, 'stop_on' => ['reply', 'bounce', 'unsubscribe']],
            ],
            [
                'kind' => 'methodology', 'role' => 'ae', 'stage' => 'qualification', 'slug' => 'qualification-bant',
                'title' => 'BANT qualification',
                'description' => 'Score a deal on Budget, Authority, Need and Timing from recorded evidence.',
                'body' => 'Score each dimension 0-100 from the evidence recorded on the deal, its contacts and its activities. 0 means no evidence; '
                    .'do not infer. Give the evidence you used and the one question that would raise the score.'.self::GROUND,
                'inputs' => ['deal', 'contacts', 'activities'],
                'output_schema' => ['dimensions' => '[{key, score, evidence, next_question}]', 'missing' => '[string]'],
                'definition' => ['dimensions' => [
                    ['key' => 'budget', 'label' => 'Budget', 'weight' => 1, 'looks_for' => 'A stated budget, funding source or approved spend'],
                    ['key' => 'authority', 'label' => 'Authority', 'weight' => 1, 'looks_for' => 'A contact with sign-off power identified and engaged'],
                    ['key' => 'need', 'label' => 'Need', 'weight' => 1, 'looks_for' => 'A specific, confirmed problem the product addresses'],
                    ['key' => 'timing', 'label' => 'Timing', 'weight' => 1, 'looks_for' => 'A dated event or deadline driving the decision'],
                ]],
            ],
            [
                'kind' => 'methodology', 'role' => 'ae', 'stage' => 'qualification', 'slug' => 'qualification-meddicc',
                'title' => 'MEDDICC qualification',
                'description' => 'Score a deal on the seven MEDDICC dimensions from recorded evidence.',
                'body' => 'Score each dimension 0-100 using only what is recorded on the deal, its contacts and activities. Treat missing evidence as 0, not as unknown-good. '
                    .'For each dimension give the evidence and the single next action that would strengthen it.'.self::GROUND,
                'inputs' => ['deal', 'contacts', 'activities'],
                'output_schema' => ['dimensions' => '[{key, score, evidence, next_action}]', 'missing' => '[string]'],
                'definition' => ['dimensions' => [
                    ['key' => 'metrics', 'label' => 'Metrics', 'weight' => 1, 'looks_for' => 'A quantified outcome the buyer wants'],
                    ['key' => 'economic_buyer', 'label' => 'Economic buyer', 'weight' => 1.5, 'looks_for' => 'The person who controls the budget is known and engaged'],
                    ['key' => 'decision_criteria', 'label' => 'Decision criteria', 'weight' => 1, 'looks_for' => 'How the buyer will judge options is written down'],
                    ['key' => 'decision_process', 'label' => 'Decision process', 'weight' => 1, 'looks_for' => 'Steps, approvers and dates to a signed deal'],
                    ['key' => 'identify_pain', 'label' => 'Identified pain', 'weight' => 1.5, 'looks_for' => 'A confirmed problem with a cost of inaction'],
                    ['key' => 'champion', 'label' => 'Champion', 'weight' => 1.5, 'looks_for' => 'An internal person actively selling on our behalf'],
                    ['key' => 'competition', 'label' => 'Competition', 'weight' => 0.5, 'looks_for' => 'Alternatives, including doing nothing, are known'],
                ]],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'ae', 'stage' => 'discovery', 'slug' => 'deal-discovery-call-analysis',
                'title' => 'Discovery call analysis',
                'description' => 'Analyse discovery notes or a transcript pasted by the rep: pain, stakeholders, gaps, and what to ask next.',
                'body' => 'Analyse the discovery notes provided. Extract the stated problems (quote the notes), the stakeholders mentioned and their roles, '
                    .'any budget, timeline or process facts, objections, and commitments made by either side. Then list what discovery has NOT yet established '
                    .'and propose the questions for the next call.'.self::GROUND,
                'inputs' => ['deal', 'notes'],
                'output_schema' => ['problems' => '[{text, quote}]', 'stakeholders' => '[{name, role}]', 'facts' => '{budget,timeline,process}', 'objections' => '[string]', 'gaps' => '[string]', 'next_questions' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'ae', 'stage' => 'negotiation', 'slug' => 'deal-coach',
                'title' => 'Deal coach',
                'description' => 'Review one live deal against a methodology and recommend the next moves.',
                'body' => 'Review the deal against the selected methodology scores and the recorded activity. Identify the biggest risks (stalled contact, no next step, '
                    .'missing economic buyer, close date slipping), the strongest assets, and the three most valuable next actions with an owner and a date suggestion. '
                    .'A deal with no next step or no activity in 14 days must be called out.'.self::GROUND,
                'inputs' => ['deal', 'contacts', 'activities', 'methodology_scores'],
                'output_schema' => ['health' => 'string', 'risks' => '[{risk, evidence}]', 'strengths' => '[string]', 'next_actions' => '[{action, why}]', 'missing' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'revops', 'stage' => 'pipeline', 'slug' => 'revops-stalled-deal-review',
                'title' => 'Stalled deal review',
                'description' => 'Look across open deals for ones with no recent activity, no next step, or a passed close date.',
                'body' => 'For each flagged deal provided, state why it is at risk using only the recorded dates, stages and next steps, and recommend whether to '
                    .'re-engage, re-qualify or close it out. Do not forecast revenue; describe pipeline hygiene only.'.self::GROUND,
                'inputs' => ['flagged_deals'],
                'output_schema' => ['deals' => '[{deal_id, risk, recommendation}]', 'missing' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'csm', 'stage' => 'renewal', 'slug' => 'cs-renewal-risk-review',
                'title' => 'Renewal risk review',
                'description' => 'Assess customer accounts for renewal risk from recorded activity and stage history.',
                'body' => 'For each customer account provided, assess renewal risk from the recorded activity (recency, direction, volume), contacts and notes. '
                    .'Say plainly when there is not enough recorded history to judge. Recommend one action per account.'.self::GROUND,
                'inputs' => ['customer_accounts', 'activities'],
                'output_schema' => ['accounts' => '[{account_id, risk, evidence, action}]', 'missing' => '[string]'],
            ],
            [
                'kind' => 'role_playbook', 'role' => 'csm', 'stage' => 'expansion', 'slug' => 'cs-expansion-opportunities',
                'title' => 'Expansion opportunities',
                'description' => 'Find customers whose recorded signals suggest a second product or wider use.',
                'body' => 'Using the research signals and activity recorded for each customer account, identify genuine expansion opportunities and the evidence for each. '
                    .'Do not propose an expansion with no recorded basis.'.self::GROUND,
                'inputs' => ['customer_accounts', 'signals', 'product_profile'],
                'output_schema' => ['opportunities' => '[{account_id, opportunity, evidence}]', 'missing' => '[string]'],
            ],
        ];
    }
}
