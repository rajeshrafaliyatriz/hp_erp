<?php

namespace App\Domain\Gtm\Workflows;

use App\Domain\Gtm\DealHealth;
use App\Domain\Gtm\GtmEvents;
use App\Domain\Gtm\GtmDeal;
use App\Domain\Gtm\Marketing\SegmentCriteria;
use Illuminate\Support\Facades\DB;

/**
 * What a workflow may be built from: its triggers (the triggerable gtm.* events), the facts each trigger
 * exposes to conditions and templates, and the actions. The screen builds its forms from this, and every
 * workflow is validated against it on save, so a definition can only ever reference things that exist.
 *
 * Facts come from the database at run time, scoped to the event's tenant. Contact e-mail addresses and phone
 * numbers are deliberately NOT available: a workflow can ask whether a contact HAS an email, not read it.
 */
final class WorkflowCatalog
{
    /** which entities a trigger's event makes available, by the table the event is about */
    public const PROVIDES = [
        'gtm_accounts' => ['account'], 'gtm_contacts' => ['contact', 'account'], 'gtm_deals' => ['deal', 'account'],
        'gtm_outreach_messages' => ['message', 'contact', 'account'], 'gtm_renewals' => ['renewal', 'account'],
    ];

    /** @var array<string, array<string, array{type: string, label: string, options?: list<string>}>> */
    private const FIELDS = [
        'account' => [
            'account.id' => ['type' => 'number', 'label' => 'Account id'], 'account.name' => ['type' => 'string', 'label' => 'Account name'],
            'account.stage' => ['type' => 'enum', 'label' => 'Account stage', 'options' => SegmentCriteria::ACCOUNT_STAGES],
            'account.industry' => ['type' => 'string', 'label' => 'Industry'], 'account.location' => ['type' => 'string', 'label' => 'Location'],
            'account.icp_fit_score' => ['type' => 'number', 'label' => 'ICP fit score'], 'account.source' => ['type' => 'enum', 'label' => 'Account source', 'options' => SegmentCriteria::SOURCES],
            'account.owner_user_id' => ['type' => 'number', 'label' => 'Account owner id'], 'account.contacts_count' => ['type' => 'number', 'label' => 'Number of contacts'],
            'account.has_contacts' => ['type' => 'bool', 'label' => 'Has contacts'],
        ],
        'contact' => [
            'contact.id' => ['type' => 'number', 'label' => 'Contact id'], 'contact.full_name' => ['type' => 'string', 'label' => 'Contact name'], 'contact.title' => ['type' => 'string', 'label' => 'Contact title'],
            'contact.role_in_deal' => ['type' => 'enum', 'label' => 'Buying role', 'options' => SegmentCriteria::CONTACT_ROLES],
            'contact.status' => ['type' => 'enum', 'label' => 'Contact status', 'options' => SegmentCriteria::CONTACT_STATUSES], 'contact.has_email' => ['type' => 'bool', 'label' => 'Has an email address'],
        ],
        'deal' => [
            'deal.id' => ['type' => 'number', 'label' => 'Deal id'], 'deal.name' => ['type' => 'string', 'label' => 'Deal name'],
            'deal.stage' => ['type' => 'enum', 'label' => 'Deal stage', 'options' => GtmDeal::STAGES], 'deal.amount' => ['type' => 'number', 'label' => 'Deal amount'],
            'deal.currency' => ['type' => 'string', 'label' => 'Deal currency'], 'deal.days_in_stage' => ['type' => 'number', 'label' => 'Days in current stage'],
            'deal.has_next_step' => ['type' => 'bool', 'label' => 'Has a next step'], 'deal.forecast_category' => ['type' => 'enum', 'label' => 'Forecast category', 'options' => ['pipeline', 'best_case', 'commit', 'omitted']],
        ],
        'message' => [
            'message.id' => ['type' => 'number', 'label' => 'Message id'], 'message.status' => ['type' => 'string', 'label' => 'Message status'],
            'message.step' => ['type' => 'number', 'label' => 'Sequence step'], 'message.has_campaign' => ['type' => 'bool', 'label' => 'Belongs to a campaign'],
        ],
        'renewal' => [
            'renewal.id' => ['type' => 'number', 'label' => 'Renewal id'], 'renewal.name' => ['type' => 'string', 'label' => 'Renewal name'],
            'renewal.status' => ['type' => 'enum', 'label' => 'Renewal status', 'options' => ['upcoming', 'in_progress', 'renewed', 'churned', 'cancelled']],
            'renewal.days_until' => ['type' => 'number', 'label' => 'Days until renewal (negative = overdue)'], 'renewal.amount' => ['type' => 'number', 'label' => 'Renewal amount'],
            'renewal.currency' => ['type' => 'string', 'label' => 'Renewal currency'],
        ],
    ];

    /** facts that come from the event's own payload, per event type */
    private const EVENT_FIELDS = [
        'gtm.deal.stage_changed' => ['event.from' => ['type' => 'enum', 'label' => 'Stage moved from', 'options' => GtmDeal::STAGES], 'event.to' => ['type' => 'enum', 'label' => 'Stage moved to', 'options' => GtmDeal::STAGES]],
        'gtm.deal.health_flagged' => ['event.flag' => ['type' => 'enum', 'label' => 'Health flag raised', 'options' => []]],
        'gtm.renewal.due_soon' => ['event.kind' => ['type' => 'enum', 'label' => 'Alert kind', 'options' => ['overdue', 'due_soon_not_started']], 'event.days' => ['type' => 'number', 'label' => 'Days until renewal']],
        'gtm.renewal.status_changed' => ['event.from' => ['type' => 'enum', 'label' => 'Status moved from', 'options' => ['upcoming', 'in_progress']], 'event.to' => ['type' => 'enum', 'label' => 'Status moved to', 'options' => ['in_progress', 'renewed', 'churned', 'cancelled']]],
        'gtm.account.icp_scored' => ['event.score' => ['type' => 'number', 'label' => 'ICP score just calculated']],
        'gtm.customer.at_risk' => ['event.score' => ['type' => 'number', 'label' => 'Health score']],
    ];

    public const AGENT_INPUTS = [
        'gtm-prospecting' => ['mode', 'account_id'], 'gtm-outreach' => ['account_id', 'contact_id', 'mode', 'campaign_id'],
        'gtm-deal-coach' => ['deal_id'], 'gtm-revops' => [], 'gtm-customer-success' => ['mode'],
    ];

    /**
     * @return array<string, array{label: string, params: array<string, string>, needs: list<string>, consequential: bool, description: string}>
     */
    public static function actions(): array
    {
        return [
            'log_activity' => ['label' => 'Log a note on the record', 'needs' => ['account'], 'consequential' => false, 'description' => 'Adds a note or task to the account timeline (and the deal or contact, when there is one). Supports {{field}} placeholders.',
                'params' => ['subject' => 'text, required, supports {{field}}', 'type' => 'note | task', 'body' => 'text, optional']],
            'set_next_step' => ['label' => 'Set the deal\'s next step', 'needs' => ['deal'], 'consequential' => false, 'description' => 'Writes the next step on an OPEN deal. By default only when it has none.',
                'params' => ['text' => 'text, required, supports {{field}}', 'due_in_days' => 'whole number 0-365, optional', 'only_if_empty' => 'true | false (default true)']],
            'run_agent' => ['label' => 'Run a GTM agent', 'needs' => [], 'consequential' => false, 'description' => 'Runs one of the five read-only agents through the central AI configuration. For the Outreach agent, save_drafts stores the result as DRAFTS - they still need a person to approve them.',
                'params' => ['agent' => implode(' | ', array_keys(self::AGENT_INPUTS)), 'input' => 'object: each value is a fixed value or "@field" to take it from the record', 'save_drafts' => 'true | false (Outreach agent only)']],
            'add_to_campaign' => ['label' => 'Add to a campaign', 'needs' => ['account'], 'consequential' => false, 'description' => 'Adds the account (and the contact, when there is one) as a target of an open campaign.',
                'params' => ['campaign_id' => 'id of one of your open campaigns']],
            'change_account_stage' => ['label' => 'Change the account stage', 'needs' => ['account'], 'consequential' => true, 'description' => 'Moves the account to another stage. Consequential: a run needs an administrator\'s approval before it executes.',
                'params' => ['stage' => implode(' | ', SegmentCriteria::ACCOUNT_STAGES)]],
        ];
    }

    /** @return array<string, array{label: string, entity: ?string, provides: list<string>, fields: array<string, array<string, mixed>>}> */
    public static function triggers(): array
    {
        $out = [];
        foreach (GtmEvents::triggerable() as $type) {
            $entity = GtmEvents::TYPES[$type]['entity'];
            $provides = self::PROVIDES[$entity] ?? [];
            $fields = [];
            foreach ($provides as $p) {
                $fields += self::FIELDS[$p];
            }
            $fields += self::EVENT_FIELDS[$type] ?? [];
            if ($type === 'gtm.deal.health_flagged') {
                $fields['event.flag']['options'] = array_keys(DealHealth::FLAGS);
            }
            $out[$type] = ['label' => GtmEvents::TYPES[$type]['label'], 'entity' => $entity, 'provides' => $provides, 'fields' => $fields];
        }

        return $out;
    }

    /**
     * Every problem with a definition, as plain sentences (empty = valid). Checks against this catalogue and, for
     * campaigns, this tenant's own records.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $actions
     * @return list<string>
     */
    public static function validate(int $tenant, string $trigger, array $conditions, array $actions): array
    {
        $errors = [];
        $triggers = self::triggers();
        if (! isset($triggers[$trigger])) {
            return ["'{$trigger}' is not an event a workflow can start from."];
        }
        $t = $triggers[$trigger];
        if (count($conditions) > 10) {
            $errors[] = 'At most 10 conditions.';
        }
        foreach ($conditions as $i => $c) {
            $n = $i + 1;
            if (! is_array($c) || ! isset($c['field'], $c['op'])) {
                $errors[] = "Condition {$n} needs a field and an operator.";

                continue;
            }
            if (! isset($t['fields'][$c['field']])) {
                $errors[] = "Condition {$n}: '{$c['field']}' is not a fact this trigger provides.";
            }
            if (! in_array($c['op'], ConditionEvaluator::OPS, true)) {
                $errors[] = "Condition {$n}: unknown operator '{$c['op']}'.";
            } elseif (! in_array($c['op'], ['empty', 'not_empty'], true) && ! array_key_exists('value', $c)) {
                $errors[] = "Condition {$n} needs a value.";
            } elseif (in_array($c['op'], ['in', 'not_in'], true) && ! is_array($c['value'] ?? null)) {
                $errors[] = "Condition {$n}: '{$c['op']}' needs a list of values.";
            } elseif (in_array($c['op'], ['gt', 'gte', 'lt', 'lte'], true) && (($t['fields'][$c['field']]['type'] ?? '') !== 'number' || ! is_numeric($c['value'] ?? null))) {
                $errors[] = "Condition {$n}: '{$c['op']}' compares numbers, and needs a number fact and a number.";
            }
        }
        if ($actions === []) {
            $errors[] = 'Add at least one action.';
        }
        if (count($actions) > 6) {
            $errors[] = 'At most 6 actions.';
        }
        $catalog = self::actions();
        foreach ($actions as $i => $a) {
            $n = $i + 1;
            $type = is_array($a) ? ($a['type'] ?? null) : null;
            if (! is_string($type) || ! isset($catalog[$type])) {
                $errors[] = "Action {$n}: unknown action type.";

                continue;
            }
            foreach ($catalog[$type]['needs'] as $need) {
                if (! in_array($need, $t['provides'], true)) {
                    $errors[] = "Action {$n} ({$type}) needs a {$need}, which this trigger does not provide.";
                }
            }
            $errors = array_merge($errors, self::validateAction($tenant, $n, $type, (array) ($a['params'] ?? []), $t));
        }

        return $errors;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $t @return list<string> */
    private static function validateAction(int $tenant, int $n, string $type, array $p, array $t): array
    {
        $e = [];
        $placeholders = function (?string $text) use ($t, $n, &$e) {
            if (preg_match_all('/\{\{\s*([a-z_.]+)\s*\}\}/i', (string) $text, $m)) {
                foreach ($m[1] as $f) {
                    if (! isset($t['fields'][$f])) {
                        $e[] = "Action {$n}: '{{{$f}}}' is not a fact this trigger provides.";
                    }
                }
            }
        };
        switch ($type) {
            case 'log_activity':
                $s = trim((string) ($p['subject'] ?? ''));
                if ($s === '' || mb_strlen($s) > 255) {
                    $e[] = "Action {$n}: a subject of 1-255 characters is required.";
                }
                if (isset($p['type']) && ! in_array($p['type'], ['note', 'task'], true)) {
                    $e[] = "Action {$n}: type must be note or task.";
                }
                if (isset($p['body']) && mb_strlen((string) $p['body']) > 2000) {
                    $e[] = "Action {$n}: the body is limited to 2000 characters.";
                }
                $placeholders($s.' '.($p['body'] ?? ''));
                break;
            case 'set_next_step':
                $s = trim((string) ($p['text'] ?? ''));
                if ($s === '' || mb_strlen($s) > 1000) {
                    $e[] = "Action {$n}: text of 1-1000 characters is required.";
                }
                if (isset($p['due_in_days']) && (! is_int($p['due_in_days']) || $p['due_in_days'] < 0 || $p['due_in_days'] > 365)) {
                    $e[] = "Action {$n}: due_in_days must be a whole number from 0 to 365.";
                }
                if (isset($p['only_if_empty']) && ! is_bool($p['only_if_empty'])) {
                    $e[] = "Action {$n}: only_if_empty must be true or false.";
                }
                $placeholders($s);
                break;
            case 'run_agent':
                $agent = $p['agent'] ?? null;
                if (! is_string($agent) || ! isset(self::AGENT_INPUTS[$agent])) {
                    $e[] = "Action {$n}: choose one of: ".implode(', ', array_keys(self::AGENT_INPUTS)).'.';
                    break;
                }
                foreach ((array) ($p['input'] ?? []) as $k => $v) {
                    if (! in_array($k, self::AGENT_INPUTS[$agent], true)) {
                        $e[] = "Action {$n}: '{$k}' is not an input of {$agent}.";
                    } elseif (is_string($v) && str_starts_with($v, '@') && ! isset($t['fields'][substr($v, 1)])) {
                        $e[] = "Action {$n}: '{$v}' is not a fact this trigger provides.";
                    }
                }
                if (! empty($p['save_drafts']) && $agent !== 'gtm-outreach') {
                    $e[] = "Action {$n}: save_drafts only applies to gtm-outreach.";
                }
                break;
            case 'add_to_campaign':
                $id = $p['campaign_id'] ?? null;
                if (! is_int($id) || ! DB::table('gtm_campaigns')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('status', ['draft', 'active', 'paused'])->where('id', $id)->exists()) {
                    $e[] = "Action {$n}: choose one of your own open campaigns.";
                }
                break;
            case 'change_account_stage':
                if (! in_array($p['stage'] ?? null, SegmentCriteria::ACCOUNT_STAGES, true)) {
                    $e[] = "Action {$n}: stage must be one of ".implode(', ', SegmentCriteria::ACCOUNT_STAGES).'.';
                }
                break;
        }

        return $e;
    }

    /** True when any action in the list is consequential (forces an approval step). @param list<array<string, mixed>> $actions */
    public static function hasConsequential(array $actions): bool
    {
        $catalog = self::actions();

        return (bool) array_filter($actions, fn ($a) => ($catalog[$a['type'] ?? '']['consequential'] ?? false) === true);
    }

    /** Replace {{field}} with the real value. Unknown or missing facts become empty - never a placeholder left in text. @param array<string, mixed> $values */
    public static function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/i', function ($m) use ($values) {
            $v = $values[$m[1]] ?? null;

            return is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? (string) $v : '');
        }, $template) ?? $template;
    }
}
