<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\BuyerResolver;
use App\Domain\Signals\Market\QualificationGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketQualificationGateTest extends TestCase
{
    private function record(array $over = []): array
    {
        return array_replace_recursive([
            'buyer' => ['name' => 'Example State Department of School Education', 'type' => 'government', 'state' => 'Exampleland'],
            'trigger_type' => 'tender', 'trigger_summary' => 'Tender for an assessment platform',
            'source_url' => 'https://example.invalid/t/1', 'event_date' => '2026-10-01',
            'claim_level' => 'confirmed', 'fetch_level' => 'full_document',
        ], $over);
    }

    private function ok(array $r): array
    {
        $v = (new QualificationGate())->evaluate($r);
        $this->assertTrue($v['ok'], $v['reason'] ?? '');

        return $v['record'];
    }

    // ── rejections ───────────────────────────────────────────────────────────

    #[DataProvider('rejections')]
    public function test_record_is_rejected_with_a_reason_code(array $mutate, string $code): void
    {
        $r = $this->record();
        foreach ($mutate as $k => $v) {
            if ($v === null) {
                unset($r[$k]);
            } else {
                $r[$k] = $v;
            }
        }

        $v = (new QualificationGate())->evaluate($r);

        $this->assertFalse($v['ok']);
        $this->assertSame($code, $v['code']);
    }

    public static function rejections(): array
    {
        return [
            'no buyer' => [['buyer' => ['name' => '  ']], 'missing_buyer'],
            'no trigger' => [['trigger_summary' => null], 'missing_trigger'],
            'no date' => [['event_date' => null], 'missing_date'],
            'garbage date' => [['event_date' => 'not-a-date'], 'invalid_date'],
            'no url' => [['source_url' => null], 'missing_source_url'],
            'not an http url' => [['source_url' => 'ftp://x.y/z'], 'missing_source_url'],
            'bad claim level' => [['claim_level' => 'certain'], 'invalid_claim_level'],
            'bad fetch level' => [['fetch_level' => null], 'invalid_fetch_level'],
            'bad trigger type' => [['trigger_type' => 'rumour'], 'invalid_trigger_type'],
            'bad business fit' => [['business_fit' => 'all'], 'invalid_business_fit'],
            'bad need code' => [['candidate_need_codes' => ['N21']], 'invalid_need_code'],
            'partial scores' => [['scores' => ['buying_signal' => 3]], 'invalid_scores'],
            'score out of range' => [['scores' => array_fill_keys(QualificationGate::SCORE_KEYS, 6)], 'invalid_scores'],
        ];
    }

    public function test_observed_at_alone_satisfies_the_date_rule(): void
    {
        $r = $this->record(['observed_at' => '2026-10-02T10:00:00']);
        unset($r['event_date']);

        $rec = $this->ok($r);

        $this->assertNull($rec['event_date']);
        $this->assertSame('2026-10-02 10:00:00', $rec['observed_at']);
    }

    // ── evidence grade ───────────────────────────────────────────────────────

    #[DataProvider('weakFetches')]
    public function test_a_weak_fetch_can_never_be_confirmed(string $fetch): void
    {
        $rec = $this->ok($this->record(['fetch_level' => $fetch]));

        $this->assertSame('inference', $rec['claim_level']);
        $this->assertStringContainsString($fetch, (string) $rec['evidence_note']);
    }

    public static function weakFetches(): array
    {
        return [['search_snippet'], ['blocked']];
    }

    public function test_a_strong_fetch_keeps_confirmed_and_inference_is_never_upgraded(): void
    {
        $this->assertSame('confirmed', $this->ok($this->record(['fetch_level' => 'page_text']))['claim_level']);
        $this->assertSame('inference', $this->ok($this->record(['claim_level' => 'inference', 'fetch_level' => 'full_document']))['claim_level']);
        $this->assertNull($this->ok($this->record())['evidence_note']);
    }

    // ── expiry ───────────────────────────────────────────────────────────────

    public function test_expiry_is_explicit_then_tender_closing_date_then_none(): void
    {
        $this->assertSame('2026-11-05', $this->ok($this->record(['expires_at' => '2026-11-05']))['expires_at']);
        $this->assertSame('2026-11-09', $this->ok($this->record(['closing_date' => '2026-11-09']))['expires_at']);
        $this->assertNull($this->ok($this->record(['trigger_type' => 'funding', 'closing_date' => '2026-11-09']))['expires_at'], 'only tenders derive expiry from a closing date');
        $this->assertNull($this->ok($this->record())['expires_at']);
    }

    // ── government track ─────────────────────────────────────────────────────

    public function test_central_capacity_building_signals_go_through_an_si_partner(): void
    {
        $rec = $this->ok($this->record([
            'buyer' => ['name' => 'Capacity Building Commission', 'type' => 'government', 'state' => null],
            'trigger_summary' => 'Mission Karmayogi competency framework rollout',
        ]));

        $this->assertTrue($rec['is_government_track']);
        $this->assertSame('si_partnership', $rec['entry_point']);
    }

    public function test_state_departments_and_institutions_are_not_auto_flagged(): void
    {
        $state = $this->ok($this->record(['trigger_summary' => 'CBP aligned training for the state']));
        $this->assertFalse($state['is_government_track'], 'a named state is not central government');

        $inst = $this->ok($this->record(['buyer' => ['name' => 'Karmayogi Institute', 'type' => 'institutional', 'state' => null]]));
        $this->assertFalse($inst['is_government_track']);
    }

    public function test_an_explicit_government_flag_and_entry_point_are_honoured(): void
    {
        $rec = $this->ok($this->record(['is_government_track' => true, 'entry_point' => 'pilot']));

        $this->assertTrue($rec['is_government_track']);
        $this->assertSame('pilot', $rec['entry_point']);
    }

    // ── scores ───────────────────────────────────────────────────────────────

    public function test_scores_are_stored_as_given_with_a_total(): void
    {
        $rec = $this->ok($this->record(['scores' => array_fill_keys(QualificationGate::SCORE_KEYS, 4) + ['reasoning' => 'why']]));

        $this->assertSame(28, $rec['scores']['total']);
        $this->assertSame('why', $rec['score_reasoning']);
    }

    public function test_no_scores_means_no_scores_not_invented_ones(): void
    {
        $this->assertNull($this->ok($this->record())['scores']);
        $this->assertNull($this->ok($this->record(['scores' => ['reasoning' => 'only a note']]))['scores']);
    }

    public function test_need_codes_accept_a_delimited_string_and_dedupe(): void
    {
        $this->assertSame(['N01', 'N20'], $this->ok($this->record(['candidate_need_codes' => 'n01| N20 ,N01']))['candidate_need_codes']);
    }

    // ── has the signal advanced? ─────────────────────────────────────────────

    public function test_advancement_rules(): void
    {
        $stored = ['event_date' => '2026-10-01', 'expires_at' => '2026-10-30', 'claim_level' => 'inference', 'fetch_level' => 'page_text'];
        $same = ['event_date' => '2026-10-01', 'expires_at' => '2026-10-30', 'claim_level' => 'inference', 'fetch_level' => 'page_text'];

        $this->assertFalse(QualificationGate::hasAdvanced($stored, $same));
        $this->assertFalse(QualificationGate::hasAdvanced($stored, ['expires_at' => null, 'event_date' => '2026-09-01'] + $same), 'an older date or a missing deadline is not progress');
        $this->assertTrue(QualificationGate::hasAdvanced($stored, ['event_date' => '2026-10-05'] + $same));
        $this->assertTrue(QualificationGate::hasAdvanced($stored, ['expires_at' => '2026-11-15'] + $same), 'a moved deadline is an amendment');
        $this->assertTrue(QualificationGate::hasAdvanced($stored, ['claim_level' => 'confirmed'] + $same));
        $this->assertTrue(QualificationGate::hasAdvanced($stored, ['fetch_level' => 'full_document'] + $same));
        $this->assertFalse(QualificationGate::hasAdvanced($stored, ['claim_level' => 'hypothesis'] + $same), 'weaker evidence is not progress');
    }

    // ── buyer names ──────────────────────────────────────────────────────────

    public function test_buyer_names_that_differ_only_in_punctuation_and_abbreviation_normalise_alike(): void
    {
        $a = BuyerResolver::strongNormalise('Dept. of School Education, Punjab');
        $b = BuyerResolver::strongNormalise('Department of School Education - Punjab');
        $c = BuyerResolver::strongNormalise('Dept of School Education Haryana');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertSame('tata sons limited', BuyerResolver::strongNormalise('Tata & Sons Ltd.'));
    }
}
