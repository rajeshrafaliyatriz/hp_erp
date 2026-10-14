<?php

namespace Tests\Unit;

use App\Domain\Signals\Market\SignalStrength;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** How hot a ScholarClone signal is comes from plain rules, never from the model scoring itself. Pure. */
class SignalStrengthTest extends TestCase
{
    private function assess(array $s): array
    {
        return (new SignalStrength())->assess($s + ['quote_verified' => true], Carbon::parse('2026-10-20'));
    }

    public function test_an_open_tender_is_hot_until_it_closes(): void
    {
        $open = $this->assess(['trigger_type' => 'tender', 'intent' => 'direct_need', 'event_date' => '2026-10-01', 'expires_at' => '2026-11-05']);
        $this->assertSame('hot', $open['strength']);
        $this->assertStringContainsString('closes in 16 days', $open['reason']);

        $closed = $this->assess(['trigger_type' => 'tender', 'intent' => 'direct_need', 'event_date' => '2026-09-01', 'expires_at' => '2026-10-10']);
        $this->assertSame('watch', $closed['strength']);
    }

    public function test_a_fresh_direct_need_is_hot_and_an_old_one_is_not(): void
    {
        $fresh = $this->assess(['trigger_type' => 'vendor_search', 'intent' => 'direct_need', 'event_date' => '2026-10-10']);
        $this->assertSame('hot', $fresh['strength']);
        $this->assertSame(10, $fresh['age_days']);

        $old = $this->assess(['trigger_type' => 'vendor_search', 'intent' => 'direct_need', 'event_date' => '2026-08-01']);
        $this->assertSame('watch', $old['strength']);
    }

    public function test_an_event_that_usually_leads_to_a_purchase_is_warm_not_hot(): void
    {
        $this->assertSame('warm', $this->assess(['trigger_type' => 'expansion', 'intent' => 'likely_need', 'event_date' => '2026-09-20'])['strength']);
        $this->assertSame('warm', $this->assess(['trigger_type' => 'leadership', 'intent' => 'likely_need', 'source_published_at' => '2026-08-25'])['strength']);
    }

    public function test_an_undated_source_is_never_hot_warm_or_act_this_month_whatever_it_says(): void
    {
        foreach (['direct_need', 'likely_need', 'context'] as $intent) {
            foreach (['hiring', 'vendor_search', 'expansion', 'leadership', 'pain_point'] as $trigger) {
                $r = $this->assess(['trigger_type' => $trigger, 'intent' => $intent]);
                $this->assertSame('watch', $r['strength'], "{$trigger}/{$intent}");
                $this->assertNull($r['age_days'], 'an unknown date is not an age of zero');
                $intent === 'context' || $this->assertStringContainsString('unknown', $r['reason']);
            }
        }
        // Only the day we FOUND it is known: that is not a publication date.
        $found = $this->assess(['trigger_type' => 'hiring', 'intent' => 'direct_need', 'observed_at' => '2026-10-20']);
        $this->assertSame('watch', $found['strength']);
        $this->assertNull($found['age_days']);
    }

    public function test_an_undated_tender_with_no_closing_date_is_watch_not_open(): void
    {
        $r = $this->assess(['trigger_type' => 'tender', 'intent' => 'direct_need']);
        $this->assertSame('watch', $r['strength']);
        $this->assertStringContainsString('not known to be open', $r['reason']);
    }

    public function test_an_unverified_quote_can_never_make_a_signal_hot(): void
    {
        $r = (new SignalStrength())->assess(['trigger_type' => 'hiring', 'intent' => 'direct_need', 'event_date' => '2026-10-18', 'quote_verified' => false], Carbon::parse('2026-10-20'));
        $this->assertSame('warm', $r['strength']);
    }

    public function test_background_is_only_ever_watch(): void
    {
        $this->assertSame('watch', $this->assess(['trigger_type' => 'expansion', 'intent' => 'context', 'event_date' => '2026-10-19'])['strength']);
    }

    public function test_strength_maps_to_the_stored_band_and_feed_section(): void
    {
        $this->assertSame('act_this_week', SignalStrength::BAND['hot']);
        $this->assertSame('immediate_action', SignalStrength::SECTION['hot']);
        $this->assertSame('watchlist', SignalStrength::SECTION['warm']);
    }

    public function test_hiring_alone_is_never_warm_it_needs_a_project_level_buying_indicator(): void
    {
        $recent = ['trigger_type' => 'hiring', 'intent' => 'likely_need', 'event_date' => '2026-10-02'];

        foreach (['unconfirmed', 'weak'] as $level) {
            $r = $this->assess($recent + ['buying_intent' => $level]);
            $this->assertSame('watch', $r['strength'], $level);
            $this->assertStringContainsString('Hiring on its own does not show', $r['reason']);
        }
        $this->assertSame('warm', $this->assess($recent + ['buying_intent' => 'moderate'])['strength'], 'a system-role post is a project-level indicator');
        $this->assertSame('hot', $this->assess(['trigger_type' => 'hiring', 'intent' => 'direct_need', 'buying_intent' => 'strong', 'event_date' => '2026-10-14'])['strength']);

        // Other triggers are not affected by this rule.
        $this->assertSame('warm', $this->assess(['trigger_type' => 'expansion', 'intent' => 'likely_need', 'buying_intent' => 'weak', 'event_date' => '2026-10-02'])['strength']);
        // A caller that supplies no assessed level gets the earlier behaviour (backward compatible).
        $this->assertSame('warm', $this->assess($recent)['strength']);
    }
}
