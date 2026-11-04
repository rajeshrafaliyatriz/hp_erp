<?php

namespace Tests\Feature;

use App\Domain\Facts\ConsentGate;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsFactsWorld;
use Tests\TestCase;

/**
 * `consent:reconcile` (slice 4, defect D7): brings the four consent places into agreement for one organisation for what was recorded before the handlers wrote
 * through. DB-RULES for a data update: dry run by default and exactly what --apply would do, one organisation, only ever escalates, prints counts, and a
 * second run changes nothing.
 */
class FactsConsentReconcileTest extends TestCase
{
    use BuildsFactsWorld;

    /** @var array<string, int> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFactsWorld();

        $c = fn (string $email, array $over = []) => $this->makeContact(self::T, ['email' => $email] + $over);
        $this->r = [
            'A' => $c('a@school.example', ['email_opt_out' => 1]),                                   // opted out on the record only
            'B' => $this->makeLead(self::T, ['email' => 'a@school.example']),                       // the same address, nothing recorded
            'C' => $c('sup@school.example'),                                                         // its address is on the suppression list (a complaint)
            'D' => $c('hb@school.example'),                                                          // its address is on the list (a hard bounce)
            'E' => $c('out@school.example'),                                                         // an outreach contact with this address unsubscribed
            'F' => $this->makeLead(self::T, ['email' => 'bnc@school.example']),                    // an outreach contact with this address bounced
            'G' => $c('g@school.example'),                                                           // an email preference says opted out, nothing else does
            'I' => $c('different@school.example'),                                                   // the contact a lead was converted into
            'J' => $c('clean@school.example'),                                                       // nothing against it, an SMS opt-in that must stay
            'K' => $this->makeContact(self::T2, ['email' => 'a@school.example']),                   // another organisation, same address as A
        ];
        $this->r['H'] = $this->makeLead(self::T, ['email' => 'conv@school.example', 'email_opt_out' => 1, 'consent_status' => 'opted_out', 'converted' => 1, 'converted_contact_id' => $this->r['I']]);

        DB::table('gtm_suppressions')->insert([
            ['sub_institute_id' => self::T, 'kind' => 'email', 'value' => 'sup@school.example', 'reason' => 'complaint', 'created_at' => now()],
            ['sub_institute_id' => self::T, 'kind' => 'email', 'value' => 'hb@school.example', 'reason' => 'hard_bounce', 'created_at' => now()],
            ['sub_institute_id' => self::T, 'kind' => 'domain', 'value' => 'blocked.example', 'reason' => 'manual', 'created_at' => now()],
        ]);
        foreach ([['out@school.example', 'unsubscribed'], ['bnc@school.example', 'bounced']] as [$email, $status]) {
            DB::table('gtm_contacts')->insert(['sub_institute_id' => self::T, 'account_id' => 1, 'full_name' => 'P', 'email' => $email, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('crm_consent_preferences')->insert([
            ['entity_type' => 'contact', 'entity_id' => $this->r['G'], 'channel' => 'email', 'status' => 'opted_out', 'source' => 'form', 'sub_institute_id' => self::T],
            ['entity_type' => 'contact', 'entity_id' => $this->r['J'], 'channel' => 'sms', 'status' => 'opted_in', 'source' => 'form', 'sub_institute_id' => self::T],
        ]);
    }

    /** @return array<string, mixed> every row of every table the command may read or write */
    private function snapshot(): array
    {
        $all = [];
        foreach (['crm_contacts', 'crm_leads', 'crm_organizations', 'crm_consent_preferences', 'gtm_suppressions', 'gtm_contacts', 'g2g_event'] as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $all;
    }

    private function reconcile(array $options): string
    {
        $this->assertSame(0, Artisan::call('consent:reconcile', ['--tenant' => self::T] + $options));

        return Artisan::output();
    }

    private function blocked(string $type, int $id, string $channel = 'email'): bool
    {
        return ! app(ConsentGate::class)->allows($type, $id, $channel)['allowed'];
    }

    public function test_a_dry_run_is_the_default_writes_nothing_and_says_what_it_would_do(): void
    {
        $before = $this->snapshot();

        $out = $this->reconcile([]);

        $this->assertSame($before, $this->snapshot(), 'a dry run changes not one row');
        $this->assertStringContainsString('MODE: DRY RUN (nothing was written)', $out);
        $this->assertStringContainsString('would change:', $out);
        $this->assertStringContainsString('Run again with --apply to do this.', $out);
        $this->assertMatchesRegularExpression('/records flagged or suppressed \.+ (\d+)/', $out);
        $this->assertStringContainsString('whole-domain suppression entries ..... 1', $out);
        $this->assertFalse($this->blocked('lead', $this->r['B']), 'and so the lead is still emailable, as before');
    }

    public function test_apply_makes_every_place_agree_and_the_gate_stops_every_record_that_should_be_stopped(): void
    {
        $dry = $this->reconcile([]);
        $out = $this->reconcile(['--apply' => true]);

        $this->assertStringContainsString('MODE: APPLIED (committed in one transaction)', $out);
        $this->assertSame(preg_replace('/MODE:.*|would change:|changed:|Run again.*|Done\..*/', '', $dry), preg_replace('/MODE:.*|would change:|changed:|Run again.*|Done\..*/', '', $out), 'the dry run reported exactly what the apply then did');

        foreach ([['contact', 'A'], ['lead', 'B'], ['contact', 'C'], ['contact', 'D'], ['contact', 'E'], ['lead', 'F'], ['contact', 'G'], ['contact', 'I']] as [$type, $key]) {
            $this->assertTrue($this->blocked($type, $this->r[$key]), "{$type} {$key} is stopped now");
        }
        $this->assertSame([1, 1], [(int) DB::table('crm_leads')->where('id', $this->r['B'])->value('email_opt_out'), (int) DB::table('crm_contacts')->where('id', $this->r['C'])->value('email_opt_out')]);
        $this->assertSame(['unsubscribed'], DB::table('gtm_suppressions')->where('value', 'a@school.example')->pluck('reason')->all(), 'A\'s address reached the outreach engine\'s list');
        $this->assertSame(['complaint'], DB::table('gtm_suppressions')->where('value', 'sup@school.example')->pluck('reason')->all(), 'an entry that was already there keeps its own reason and is not doubled');
        $this->assertSame(['unsubscribed'], DB::table('gtm_suppressions')->where('value', 'out@school.example')->pluck('reason')->all());
        $this->assertSame(['unsubscribed'], DB::table('gtm_suppressions')->where('value', 'g@school.example')->pluck('reason')->all());
        $this->assertSame(['hard_bounce'], DB::table('gtm_suppressions')->where('value', 'bnc@school.example')->pluck('reason')->all());

        $d = DB::table('crm_contacts')->where('id', $this->r['D'])->first();
        $this->assertSame([0, 'suppressed', 'hard_bounce'], [(int) $d->email_opt_out, $d->consent_status, $d->suppressed_reason], 'a bounce is suppressed, not opted out');
        $this->assertSame(0, DB::table('crm_consent_preferences')->where('entity_id', $this->r['D'])->where('entity_type', 'contact')->count());
        $this->assertSame('opted_out', DB::table('crm_consent_preferences')->where('entity_type', 'lead')->where('entity_id', $this->r['B'])->value('status'));

        $i = DB::table('crm_contacts')->where('id', $this->r['I'])->first();
        $this->assertSame([1, 'opted_out'], [(int) $i->email_opt_out, $i->consent_status], 'the converted lead\'s opt-out reached its contact');
        $this->assertSame('bounced', DB::table('gtm_contacts')->where('email', 'bnc@school.example')->value('status'), 'an outreach contact that already says so is left alone');
        $this->assertSame('unsubscribed', DB::table('gtm_contacts')->where('email', 'out@school.example')->value('status'), 'an outreach contact that already says so is left alone');
    }

    public function test_it_only_escalates_and_touches_only_the_named_organisation(): void
    {
        $this->reconcile(['--apply' => true]);

        $j = DB::table('crm_contacts')->where('id', $this->r['J'])->first();
        $this->assertSame([0, 'unknown'], [(int) $j->email_opt_out, $j->consent_status], 'a clean record stays clean');
        $this->assertSame('opted_in', DB::table('crm_consent_preferences')->where('entity_id', $this->r['J'])->where('channel', 'sms')->value('status'), 'an opt-in is never touched, never written');
        $this->assertFalse($this->blocked('contact', $this->r['J']), 'and the clean contact can still be emailed');
        $k = DB::table('crm_contacts')->where('id', $this->r['K'])->first();
        $this->assertSame([0, 'unknown'], [(int) $k->email_opt_out, $k->consent_status], 'the same address in another organisation is not touched');
        $this->assertSame(0, DB::table('crm_consent_preferences')->where('entity_id', $this->r['K'])->count());
        $this->assertSame(0, DB::table('gtm_suppressions')->where('sub_institute_id', self::T2)->count());
        $this->assertSame('manual', DB::table('gtm_suppressions')->where('kind', 'domain')->value('reason'));
        $this->assertSame(1, DB::table('gtm_suppressions')->where('kind', 'domain')->count(), 'a whole-domain entry is reported, never rewritten into addresses');
    }

    public function test_a_second_run_finds_nothing_to_do_and_a_second_apply_changes_not_one_row(): void
    {
        $this->reconcile(['--apply' => true]);
        $after = $this->snapshot();

        $again = $this->reconcile([]);
        $this->reconcile(['--apply' => true]);

        $this->assertStringContainsString('Nothing to do: the four places already agree.', $again);
        $this->assertSame($after, $this->snapshot(), 'nothing changed, not even an update time or an event');
    }

    public function test_it_needs_a_tenant_and_refuses_a_bad_one(): void
    {
        $before = $this->snapshot();

        foreach ([[], ['--tenant' => 'abc'], ['--tenant' => '0'], ['--tenant' => '-3']] as $options) {
            $this->assertSame(1, Artisan::call('consent:reconcile', $options + ['--apply' => true]));
            $this->assertStringContainsString('--tenant=<organisation id> is required', Artisan::output());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_an_organisation_with_nothing_to_reconcile_is_nothing_to_do(): void
    {
        $this->assertSame(0, Artisan::call('consent:reconcile', ['--tenant' => 77, '--apply' => true]));

        $this->assertStringContainsString('Nothing to do', Artisan::output());
    }

    public function test_the_summary_can_be_saved_to_a_file(): void
    {
        $path = storage_path('framework/testing/consent-reconcile-' . uniqid() . '/summary.txt');

        $out = $this->reconcile(['--out' => $path]);

        $this->assertFileExists($path);
        $this->assertSame(rtrim($out), rtrim((string) file_get_contents($path)));
        @unlink($path);
        @rmdir(dirname($path));
    }
}
