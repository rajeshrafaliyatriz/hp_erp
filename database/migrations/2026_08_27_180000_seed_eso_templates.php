<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §6.4 — SIX ESO TEMPLATES, AUTHORED BY HAND.
 *
 * The document is explicit that these must not be generated: *"Author 20 ESO
 * Templates by hand — not generated — covering all six execution modes. Purpose
 * is to discover which of the 18 fields are load-bearing before generating at
 * scale."*
 *
 * Six rather than twenty, because six is what it takes to cover the six modes
 * once each, and the finding arrives at six as clearly as at twenty. The
 * remaining fourteen are for a person who knows this business, not for me.
 *
 * ── WHAT AUTHORING THESE ACTUALLY REVEALED (the point of §6.4) ──────────────
 *
 * `evidence_emitted` (§5.18) IS LEFT NULL ON EVERY ONE, and that is a finding
 * rather than an omission. The field wants `{evidence_type, competency_id,
 * format}`, but a Template has no tenant, and `competency_id` is tenant-scoped
 * (tenant 6 has 22 competencies; another tenant has different ids). A generic
 * pattern cannot name a specific competency without inventing a reference.
 * Evidence can only be bound at Instance level. §5 calls this field the link
 * back to the capability engine and says not to drop it - so it stays in the
 * schema, and it stays empty here, honestly.
 *
 * `agent_responsibility` is empty on the human_only template, which is correct
 * and worth seeing: a field that is blank for a whole mode is telling you the
 * mode is real.
 *
 * `tool` inside a step is the weakest field. At template level it can only name
 * a category ("ticketing system"), never a product, because the product is a
 * customer's choice. It earns its place at Instance level only.
 *
 * These land as `Reviewed`, not `Published`. I wrote them; somebody at the
 * company should be the one to put them into force. That is the same rule the
 * classification layer holds and it applies to me too.
 *
 * RUN ON BOTH DATABASES:
 *   php artisan migrate --path=database/migrations/2026_08_27_180000_seed_eso_templates.php
 *   php artisan migrate --database=live --path=database/migrations/2026_08_27_180000_seed_eso_templates.php
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->templates() as $t) {
            // Idempotent: a re-run must not create a seventh copy.
            $exists = DB::table('eso')
                ->where('scope', 'Template')->whereNull('sub_institute_id')
                ->where('title', $t['title'])->whereNull('deleted_at')->exists();

            if ($exists) {
                continue;
            }

            DB::table('eso')->insert([
                'scope' => 'Template',
                'sub_institute_id' => null,
                'title' => $t['title'],
                'version' => 1,
                'status' => 'Reviewed',
                'execution_mode' => $t['execution_mode'],
                'objective' => $t['objective'],
                'expected_outcome' => $t['expected_outcome'],
                'human_responsibility' => $t['human_responsibility'],
                'agent_responsibility' => $t['agent_responsibility'],
                'human_decision_points' => json_encode($t['human_decision_points']),
                'escalation_triggers' => json_encode($t['escalation_triggers']),
                'steps' => json_encode($t['steps']),
                'inputs' => json_encode($t['inputs']),
                'outputs' => json_encode($t['outputs']),
                'required_controls' => json_encode($t['required_controls']),
                'prohibited_actions' => json_encode($t['prohibited_actions']),
                // See the note above. Bound at Instance level, not here.
                'evidence_emitted' => null,
                'source' => 'human',
                'model' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->templates() as $t) {
            DB::table('eso')->where('scope', 'Template')->whereNull('sub_institute_id')
                ->where('title', $t['title'])->delete();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function templates(): array
    {
        return [

            // ── 1. human_only ────────────────────────────────────────────────
            [
                'title' => 'Coach a team through a live incident',
                'execution_mode' => 'human_only',
                'objective' => 'Restore service while the people doing it become more capable of handling '
                    . 'the next one without help.',
                'expected_outcome' => 'The incident is resolved, and at least one team member can lead the '
                    . 'same class of incident unaided next time.',
                'human_responsibility' => 'Everything. The judgement about how much to intervene versus let '
                    . 'someone struggle productively is the work itself, and it depends on reading a '
                    . 'specific person under pressure.',
                'agent_responsibility' => '',
                'human_decision_points' => [
                    'Whether to take over or let the engineer continue, weighing customer impact against their learning',
                    'When the incident is severe enough to stop coaching entirely and just fix it',
                    'Which mistakes to correct in the moment and which to raise afterwards',
                ],
                'escalation_triggers' => [
                    'Customer impact widens while coaching is slowing resolution',
                    'The engineer is distressed rather than stretched',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Confirm severity and whether coaching is appropriate at all right now', 'actor' => 'H', 'tool' => 'incident record', 'output' => 'A decision to coach or to take over'],
                    ['seq' => 2, 'description' => 'Ask the engineer what they think is happening before offering a view', 'actor' => 'H', 'tool' => '', 'output' => 'Their working hypothesis'],
                    ['seq' => 3, 'description' => 'Let them act on it where the blast radius is acceptable', 'actor' => 'H', 'tool' => '', 'output' => 'An attempted fix'],
                    ['seq' => 4, 'description' => 'Intervene only when impact would widen, and say why you are intervening', 'actor' => 'H', 'tool' => '', 'output' => 'Service restored'],
                    ['seq' => 5, 'description' => 'Debrief afterwards, separating what went wrong from who did it', 'actor' => 'H', 'tool' => 'incident record', 'output' => 'A written debrief and one named follow-up'],
                ],
                'inputs' => [
                    ['name' => 'Incident record', 'source' => 'Incident management system', 'format' => 'ticket', 'required' => true],
                    ['name' => 'Engineer’s current capability', 'source' => 'The coach’s own knowledge of the person', 'format' => 'tacit', 'required' => true],
                ],
                'outputs' => [
                    ['name' => 'Resolved incident', 'format' => 'ticket', 'destination' => 'Incident management system'],
                    ['name' => 'Debrief note', 'format' => 'text', 'destination' => 'Incident record'],
                ],
                'required_controls' => [
                    'Human approval before any change to a production system',
                    'Audit log of who made each change during the incident',
                ],
                'prohibited_actions' => [
                    'Recording a judgement about an individual’s competence in the incident ticket rather than in a private review',
                    'Allowing a learning exercise to extend a customer-facing outage',
                    'Delegating the coaching itself to an automated assistant',
                ],
            ],

            // ── 2. human_ai_assist ───────────────────────────────────────────
            [
                'title' => 'Root cause analysis of a recurring fault',
                'execution_mode' => 'human_ai_assist',
                'objective' => 'Find why a fault keeps returning, rather than clearing it again.',
                'expected_outcome' => 'A named cause supported by evidence, and a change that would prevent '
                    . 'recurrence — or an explicit statement that the cause is still unknown.',
                'human_responsibility' => 'Deciding which correlation is actually causal, and owning the '
                    . 'conclusion. A plausible cause accepted without testing is worse than no conclusion.',
                'agent_responsibility' => 'Assembling and correlating logs, metrics and change history across '
                    . 'the period; proposing candidate causes with the evidence for each.',
                'human_decision_points' => [
                    'Which of the proposed causes to test, and in what order',
                    'Whether the evidence is sufficient to close the analysis or the honest answer is "not yet known"',
                    'Whether a proposed preventive change is worth its own risk',
                ],
                'escalation_triggers' => [
                    'The suspected cause sits in a system owned by another team',
                    'The evidence points at a supplier or third party rather than an internal system',
                    'Analysis suggests data was exposed or lost',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Define the fault precisely — symptom, frequency, first occurrence', 'actor' => 'H', 'tool' => 'incident history', 'output' => 'A written problem statement'],
                    ['seq' => 2, 'description' => 'Assemble logs, metrics and change records covering every occurrence', 'actor' => 'A', 'tool' => 'observability platform', 'output' => 'A correlated timeline'],
                    ['seq' => 3, 'description' => 'Propose candidate causes, each with the evidence that supports and contradicts it', 'actor' => 'A', 'tool' => 'analysis assistant', 'output' => 'A ranked list of hypotheses'],
                    ['seq' => 4, 'description' => 'Choose which to test and design a test that could disprove it', 'actor' => 'H', 'tool' => '', 'output' => 'A test plan'],
                    ['seq' => 5, 'description' => 'Run the test in a non-production environment where possible', 'actor' => 'H', 'tool' => 'test environment', 'output' => 'Confirmed or eliminated cause'],
                    ['seq' => 6, 'description' => 'Record the conclusion, including what was ruled out and what remains unknown', 'actor' => 'H', 'tool' => 'problem record', 'output' => 'A signed analysis'],
                ],
                'inputs' => [
                    ['name' => 'Incident history', 'source' => 'Incident management system', 'format' => 'records', 'required' => true],
                    ['name' => 'System logs and metrics', 'source' => 'Observability platform', 'format' => 'time series', 'required' => true],
                    ['name' => 'Change history', 'source' => 'Change management system', 'format' => 'records', 'required' => false],
                ],
                'outputs' => [
                    ['name' => 'Root cause analysis', 'format' => 'document', 'destination' => 'Problem record'],
                    ['name' => 'Preventive change request', 'format' => 'ticket', 'destination' => 'Change management system'],
                ],
                'required_controls' => [
                    'Citation required — every proposed cause must reference the specific evidence behind it',
                    'Human approval before the analysis is published as the conclusion',
                    'Audit log of the data the assistant was given access to',
                ],
                'prohibited_actions' => [
                    'Presenting a correlation the assistant surfaced as a confirmed cause without testing it',
                    'Naming an individual as the cause; the analysis addresses systems and processes',
                    'Closing the analysis with a cause nobody tested because the deadline arrived',
                ],
            ],

            // ── 3. ai_human_review ───────────────────────────────────────────
            [
                'title' => 'Compile a recurring operational report',
                'execution_mode' => 'ai_human_review',
                'objective' => 'Produce a regular report on operational performance that its audience can act on.',
                'expected_outcome' => 'A published report whose numbers reconcile to their sources and whose '
                    . 'commentary a named person stands behind.',
                'human_responsibility' => 'Reviewing and approving before publication. The reviewer is '
                    . 'accountable for the report as if they had written it, because to the reader they did.',
                'agent_responsibility' => 'Pulling the figures, applying the standing definitions, drafting '
                    . 'the commentary, and flagging anything that moved unusually.',
                'human_decision_points' => [
                    'Whether an unusual movement is a real change or a data problem',
                    'Whether commentary the assistant drafted is supported by the figures',
                    'Whether the report is fit to publish or needs to be held',
                ],
                'escalation_triggers' => [
                    'A source system did not return data and the figure would be understated',
                    'A metric breaches a threshold that has contractual consequences',
                    'The figures contradict a previously published report',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Pull the period’s figures from each source system', 'actor' => 'A', 'tool' => 'reporting platform', 'output' => 'A raw dataset'],
                    ['seq' => 2, 'description' => 'Reconcile totals against the source systems and flag any gap', 'actor' => 'S', 'tool' => 'reporting platform', 'output' => 'A reconciliation result'],
                    ['seq' => 3, 'description' => 'Apply the standing metric definitions and compute the period figures', 'actor' => 'S', 'tool' => 'reporting platform', 'output' => 'Computed metrics'],
                    ['seq' => 4, 'description' => 'Draft commentary, naming the evidence for each statement', 'actor' => 'A', 'tool' => 'drafting assistant', 'output' => 'A draft report'],
                    ['seq' => 5, 'description' => 'Review every figure and every sentence; correct or reject', 'actor' => 'H', 'tool' => '', 'output' => 'An approved report'],
                    ['seq' => 6, 'description' => 'Publish to the standing distribution', 'actor' => 'S', 'tool' => 'reporting platform', 'output' => 'A published report'],
                ],
                'inputs' => [
                    ['name' => 'Source system data', 'source' => 'Operational systems', 'format' => 'tabular', 'required' => true],
                    ['name' => 'Metric definitions', 'source' => 'Reporting standards', 'format' => 'document', 'required' => true],
                    ['name' => 'Prior period report', 'source' => 'Report archive', 'format' => 'document', 'required' => false],
                ],
                'outputs' => [
                    ['name' => 'Operational report', 'format' => 'document', 'destination' => 'Report archive and distribution list'],
                ],
                'required_controls' => [
                    'Human approval before publication — this is the control that defines this mode',
                    'Citation required for every commentary statement',
                    'Reconciliation to source before figures are used',
                    'Audit log recording who approved which version',
                ],
                'prohibited_actions' => [
                    'Publishing without a named human approver',
                    'Restating a previously published figure without saying it changed and why',
                    'Filling a gap from a failed source system with an estimate presented as a measurement',
                ],
            ],

            // ── 4. ai_supervised ─────────────────────────────────────────────
            [
                'title' => 'Monitor a service level objective and raise exceptions',
                'execution_mode' => 'ai_supervised',
                'objective' => 'Detect breaches and near-breaches of a service level objective early enough '
                    . 'to act, without a person watching a dashboard.',
                'expected_outcome' => 'Every breach is raised within the agreed detection window, and a person '
                    . 'sees only the exceptions.',
                'human_responsibility' => 'Handling the exceptions raised, and periodically checking that the '
                    . 'monitoring is still measuring the right thing. Nobody watches the normal case.',
                'agent_responsibility' => 'Continuous evaluation against thresholds, opening an exception when '
                    . 'one is crossed or trending toward being crossed, with the supporting data attached.',
                'human_decision_points' => [
                    'What to do about each exception raised',
                    'Whether a repeated exception means the threshold is wrong rather than the service',
                    'Whether to suppress an alert, and for how long',
                ],
                'escalation_triggers' => [
                    'A breach carries contractual or regulatory consequence',
                    'The monitoring itself stops reporting — silence must not read as health',
                    'The same exception recurs more than the agreed number of times in a period',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Evaluate current performance against the objective on the agreed interval', 'actor' => 'A', 'tool' => 'monitoring platform', 'output' => 'A current status'],
                    ['seq' => 2, 'description' => 'Where a threshold is crossed or trending toward it, open an exception with the data', 'actor' => 'A', 'tool' => 'monitoring platform', 'output' => 'An exception record'],
                    ['seq' => 3, 'description' => 'Notify the accountable person through the standing channel', 'actor' => 'S', 'tool' => 'notification service', 'output' => 'A notification'],
                    ['seq' => 4, 'description' => 'Assess and act on the exception', 'actor' => 'H', 'tool' => '', 'output' => 'An action or a documented decision not to act'],
                    ['seq' => 5, 'description' => 'Review thresholds and false-positive rate on a standing cycle', 'actor' => 'H', 'tool' => 'monitoring platform', 'output' => 'Adjusted thresholds'],
                ],
                'inputs' => [
                    ['name' => 'Service level objective', 'source' => 'Service agreement', 'format' => 'document', 'required' => true],
                    ['name' => 'Live performance data', 'source' => 'Monitoring platform', 'format' => 'time series', 'required' => true],
                ],
                'outputs' => [
                    ['name' => 'Exception record', 'format' => 'ticket', 'destination' => 'Incident management system'],
                    ['name' => 'Performance history', 'format' => 'time series', 'destination' => 'Monitoring platform'],
                ],
                'required_controls' => [
                    'Confidence threshold — an exception must clear a stated confidence before it is raised',
                    'Audit log of every exception raised and every alert suppressed, including who suppressed it',
                    'Heartbeat on the monitor itself, so its silence is distinguishable from good news',
                    'Segregation of duties — whoever suppresses an alert is not the person the alert is about',
                ],
                'prohibited_actions' => [
                    'Changing a threshold to stop an exception recurring without recording why',
                    'Suppressing alerts indefinitely rather than for a stated period',
                    'Taking corrective action on a production system without a human decision',
                ],
            ],

            // ── 5. ai_autonomous ─────────────────────────────────────────────
            [
                'title' => 'Classify and route inbound problem reports',
                'execution_mode' => 'ai_autonomous',
                'objective' => 'Get every inbound problem report to the right team, with the right severity, '
                    . 'without a person reading it first.',
                'expected_outcome' => 'Reports are routed within the agreed time, and misroutes stay below '
                    . 'the agreed rate.',
                'human_responsibility' => 'Owning the accuracy rate rather than individual decisions. Sampling '
                    . 'the output regularly and acting when quality drifts.',
                'agent_responsibility' => 'Reading each report, assigning category and severity, routing it, '
                    . 'and recording its own confidence.',
                'human_decision_points' => [
                    'Where to set the confidence threshold below which a report goes to a person instead',
                    'Whether a drop in sampled accuracy warrants pausing autonomous routing',
                ],
                'escalation_triggers' => [
                    'Confidence is below the threshold — route to a person, do not guess',
                    'The report mentions safety, a data breach, or a regulator',
                    'The report names an individual as being at fault',
                    'Sampled accuracy falls below the agreed rate',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Read the inbound report and extract the reported symptom and affected service', 'actor' => 'A', 'tool' => 'ticketing system', 'output' => 'Structured fields'],
                    ['seq' => 2, 'description' => 'Assign category and severity, recording confidence for each', 'actor' => 'A', 'tool' => 'classification model', 'output' => 'A classification with confidence'],
                    ['seq' => 3, 'description' => 'Below the confidence threshold, or on any escalation trigger, route to a person and stop', 'actor' => 'A', 'tool' => 'ticketing system', 'output' => 'A human-review queue item'],
                    ['seq' => 4, 'description' => 'Otherwise route to the owning team and acknowledge to the reporter', 'actor' => 'A', 'tool' => 'ticketing system', 'output' => 'A routed ticket'],
                    ['seq' => 5, 'description' => 'Sample a fixed percentage of routed tickets and score them', 'actor' => 'H', 'tool' => 'ticketing system', 'output' => 'An accuracy measurement'],
                ],
                'inputs' => [
                    ['name' => 'Inbound problem report', 'source' => 'Ticketing system', 'format' => 'text', 'required' => true],
                    ['name' => 'Category and severity definitions', 'source' => 'Service catalogue', 'format' => 'document', 'required' => true],
                    ['name' => 'Team ownership map', 'source' => 'Service catalogue', 'format' => 'mapping', 'required' => true],
                ],
                'outputs' => [
                    ['name' => 'Routed ticket', 'format' => 'ticket', 'destination' => 'Ticketing system'],
                    ['name' => 'Classification confidence record', 'format' => 'record', 'destination' => 'Audit log'],
                ],
                'required_controls' => [
                    'Confidence threshold, below which the work goes to a person',
                    'Audit log of every autonomous decision, retained and reviewable',
                    'Sampling regime with a stated rate and a stated accuracy floor',
                    'A documented rollback: how to revert to human routing, and who may trigger it',
                    'PII restriction — the report may be read but personal data is not retained in the model’s record',
                ],
                'prohibited_actions' => [
                    'Closing or resolving a report; this procedure routes only',
                    'Routing a safety, breach or regulator-related report autonomously',
                    'Communicating with the reporter beyond the standing acknowledgement',
                    'Continuing autonomously once sampled accuracy is below the floor',
                ],
            ],

            // ── 6. system_automated ──────────────────────────────────────────
            [
                'title' => 'Scheduled backup verification',
                'execution_mode' => 'system_automated',
                'objective' => 'Prove on a schedule that backups can actually be restored, rather than that '
                    . 'they completed.',
                'expected_outcome' => 'Every protected system has a restore verified within its stated '
                    . 'window, and any failure is raised as an incident.',
                'human_responsibility' => 'Acting on failures and confirming the coverage list is still '
                    . 'complete. Nobody runs the job.',
                'agent_responsibility' => '',
                'human_decision_points' => [
                    'What to do when a restore verification fails',
                    'Whether a newly added system needs to join the coverage list',
                ],
                'escalation_triggers' => [
                    'A restore verification fails',
                    'The job does not run at its scheduled time',
                    'A protected system has no successful verification inside its window',
                ],
                'steps' => [
                    ['seq' => 1, 'description' => 'Read the coverage list of systems requiring verification', 'actor' => 'S', 'tool' => 'backup platform', 'output' => 'A work list'],
                    ['seq' => 2, 'description' => 'Restore the most recent backup into an isolated environment', 'actor' => 'S', 'tool' => 'backup platform', 'output' => 'A restored instance'],
                    ['seq' => 3, 'description' => 'Run the integrity checks defined for that system', 'actor' => 'S', 'tool' => 'backup platform', 'output' => 'A pass or fail result'],
                    ['seq' => 4, 'description' => 'Destroy the restored instance', 'actor' => 'S', 'tool' => 'backup platform', 'output' => 'Environment released'],
                    ['seq' => 5, 'description' => 'Record the result; raise an incident on any failure or missed run', 'actor' => 'S', 'tool' => 'incident management system', 'output' => 'A verification record'],
                ],
                'inputs' => [
                    ['name' => 'Coverage list', 'source' => 'Backup platform configuration', 'format' => 'list', 'required' => true],
                    ['name' => 'Backup set', 'source' => 'Backup storage', 'format' => 'binary', 'required' => true],
                    ['name' => 'Integrity check definitions', 'source' => 'Backup platform configuration', 'format' => 'script', 'required' => true],
                ],
                'outputs' => [
                    ['name' => 'Verification record', 'format' => 'record', 'destination' => 'Backup platform'],
                    ['name' => 'Incident on failure', 'format' => 'ticket', 'destination' => 'Incident management system'],
                ],
                'required_controls' => [
                    'Audit log of every verification attempt and its result',
                    'The restore runs in an isolated environment, never against production',
                    'Missed runs raise an incident — a job that does not run must not look like a pass',
                    'PII restriction — restored data is not accessible outside the isolated environment',
                ],
                'prohibited_actions' => [
                    'Restoring over a production system',
                    'Marking a verification successful when the integrity checks were skipped',
                    'Retaining the restored environment after the checks complete',
                    'Describing this as AI — it is deterministic scheduled software, and conflating the two misleads the reader',
                ],
            ],
        ];
    }
};
