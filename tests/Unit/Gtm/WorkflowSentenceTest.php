<?php

namespace Tests\Unit\Gtm;

use App\Domain\Crm\Automation\LeadAutomationPresets;
use App\Domain\Gtm\Workflows\ConditionEvaluator;
use App\Domain\Gtm\Workflows\WorkflowCatalog;
use App\Domain\Gtm\Workflows\WorkflowSentence;
use Tests\TestCase;

/**
 * A workflow as one plain sentence. The wording comes from the catalogue only, so these tests pin the grammar of the pieces and prove that all 24 recommended workflows
 * read as a sentence a salesperson understands (no ids, no developer words).
 */
class WorkflowSentenceTest extends TestCase
{
    public function test_the_parts_read_in_order(): void
    {
        $this->assertSame('When a lead is created, add a note to the lead', WorkflowSentence::build('crm.lead.created', [], [['type' => 'add_lead_note']], false));
        $this->assertSame(
            'When a lead is created and "Lead score" is at least 50 and "Opted out of email" is no, add a note to the lead, create a follow-up task for the lead and set the lead status; needs your approval',
            WorkflowSentence::build('crm.lead.created', [['field' => 'lead.score', 'op' => 'gte', 'value' => 50], ['field' => 'lead.email_opt_out', 'op' => 'eq', 'value' => false]],
                [['type' => 'add_lead_note'], ['type' => 'create_lead_task'], ['type' => 'set_lead_status']], true),
        );
    }

    public function test_every_operator_has_plain_words(): void
    {
        $this->assertEqualsCanonicalizing(ConditionEvaluator::OPS, array_keys(WorkflowSentence::OPERATOR_WORDS), 'a word for every operator the engine knows');
        $words = [
            'eq' => 'is', 'neq' => 'is not', 'gt' => 'is more than', 'gte' => 'is at least', 'lt' => 'is less than', 'lte' => 'is at most',
            'in' => 'is one of', 'not_in' => 'is not one of', 'contains' => 'contains', 'empty' => 'is empty', 'not_empty' => 'is not empty',
        ];
        $this->assertSame($words, WorkflowSentence::OPERATOR_WORDS);
    }

    public function test_values_read_as_a_person_says_them(): void
    {
        $this->assertSame('yes', WorkflowSentence::value(true));
        $this->assertSame('no', WorkflowSentence::value(false));
        $this->assertSame('needs information', WorkflowSentence::value('needs_information'));
        $this->assertSame('interested, meeting requested', WorkflowSentence::value(['interested', 'meeting_requested']));
        $this->assertSame('30000', WorkflowSentence::value(30000));
        $this->assertSame('"What the reply says" is one of interested, meeting requested',
            WorkflowSentence::condition('crm.lead.reply_classified', ['field' => 'event.classification', 'op' => 'in', 'value' => ['interested', 'meeting_requested']]));
        $this->assertSame('"Lead company" is empty', WorkflowSentence::condition('crm.lead.created', ['field' => 'lead.company', 'op' => 'empty']));
    }

    public function test_an_acronym_label_keeps_its_capitals_and_a_verb_label_is_lowered(): void
    {
        $this->assertSame('run a GTM agent', WorkflowSentence::actionPhrase('run_agent'));
        $this->assertSame('email the Quote to the customer', WorkflowSentence::actionPhrase('email_quote'));
    }

    public function test_something_the_catalogue_does_not_know_still_reads_without_ids(): void
    {
        $s = WorkflowSentence::build('crm.made_up.event', [['field' => 'made_up.field', 'op' => 'eq', 'value' => 'x']], [['type' => 'do_something']], false);

        $this->assertSame('When crm made up event and "made up field" is x, do something', $s);
    }

    public function test_all_the_recommended_workflows_read_as_plain_sentences(): void
    {
        $recipes = LeadAutomationPresets::all(['support_user_id' => 1]);
        $this->assertCount(24, $recipes);
        foreach ($recipes as $d) {
            $needs = WorkflowCatalog::hasConsequential($d['actions']);
            $s = WorkflowSentence::build($d['trigger_event'], $d['conditions'], $d['actions'], $needs);

            $this->assertStringStartsWith('When ', $s, $d['key']);
            $this->assertStringNotContainsString('_', $s, "{$d['key']}: {$s}");
            $this->assertStringNotContainsString('{{', $s, $d['key']);
            $this->assertSame($needs, str_ends_with($s, '; needs your approval'), $d['key']);
            $this->assertGreaterThan(20, strlen($s));
            $this->assertLessThan(400, strlen($s), "{$d['key']} is one sentence, not a paragraph");
        }
        $this->assertCount(24, array_unique(array_column($recipes, 'key')));
    }

    public function test_titles_drop_the_step_number_the_prefix_and_the_approval_note(): void
    {
        $this->assertSame('Proposal/Quotation - prepare a draft quote', LeadAutomationPresets::plainTitle('6a Proposal/Quotation - prepare a draft quote (needs approval)'));
        $this->assertSame('Qualified - convert', LeadAutomationPresets::plainTitle('4 Qualified - convert (needs approval)'));
        $this->assertSame('Opportunity inactive', LeadAutomationPresets::plainTitle('5e Opportunity inactive'));
        $plain = LeadAutomationPresets::all(['support_user_id' => 1]);
        $prefixed = LeadAutomationPresets::all(['support_user_id' => 1, 'prefix' => 'Anything']);
        $this->assertSame(array_column($plain, 'key'), array_column($prefixed, 'key'), 'the key does not depend on the prefix an organisation chooses');
        $this->assertSame(array_column($plain, 'title'), array_column($prefixed, 'title'));
        foreach ($prefixed as $d) {
            $this->assertStringNotContainsString('Anything', $d['title']);
            $this->assertStringStartsWith('Anything ', $d['name'], 'the stored name still carries the prefix');
        }
    }
}
