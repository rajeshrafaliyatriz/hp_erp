<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Acceptance 5 of slice 10: SENDING IS IMPOSSIBLE FROM THE GATE. A static scan of every file the slice added to decide, preview or record: the gate namespace, its two
 * controllers, its routes, its config. None may call a mail transport, an HTTP client, a notification or a queue, none may touch a sending flag or write configuration, and
 * the only mention of a mailer is the read of two things the mailers already publish (the blocked-contact statuses and their `unsubscribeProblem()` answer).
 *
 * Comments are removed first (they explain what the code must not do), so the scan reads code and strings only. Pure PHPUnit TestCase on purpose: no application, no
 * database, nothing that could send.
 *
 * Also pinned here, as source order: each mailer asks the gate exactly once, after its last earlier refusal and before it claims the message or touches the transport.
 */
class OutreachGateNoSendScanTest extends TestCase
{
    private const FORBIDDEN = [
        'the Mail facade' => '~\bMail::~',
        'the Http facade' => '~\bHttp::~',
        'the Notification facade' => '~\bNotification::~',
        'the Bus or Queue facade' => '~\b(?:Bus|Queue)::~',
        'a send call' => '~->send(?:Now|Later)?\s*\(~',
        'a dispatch' => '~\bdispatch(?:Sync|Now|AfterResponse)?\s*\(~',
        'a queued job or mailable' => '~\b(?:ShouldQueue|Mailable|Notifiable|Illuminate\\\\Mail\\b)~',
        'MailSender or MailGate' => '~\bMail(?:Sender|Gate)\b~',
        'a raw mail call' => '~\bmail\s*\(|->raw\s*\(\s*\$~',
        // Other ways in to the same transport (added after the mutation proofs: an aliased import, the container name, the contract, the Symfony class, a mailer by name).
        'the Mail facade imported, so it can be aliased' => '~Facades\\\\Mail\b~',
        'a mailer contract, the mail manager or the Symfony mailer' => '~(?:Contracts\\\\Mail|Illuminate\\\\Mail|Symfony\\\\Component\\\\Mailer|mail\.manager|[\'"]mailer[\'"]|->mailer\s*\()~',
        'an HTTP client other than the facade' => '~(?:GuzzleHttp|Illuminate\\\\Http\\\\Client|Facades\\\\Http\b|\bPendingRequest\b|\bSoapClient\b|\bCURLOPT_)~',
        'a socket or curl' => '~\b(?:curl_\w+|fsockopen|stream_socket_client|stream_context_create|file_get_contents\s*\(|fopen\s*\(|readfile\s*\(|file_put_contents\s*\()~',
        'a sending flag' => '~(?:sending_enabled|SENDING_ENABLED|scheduler_enabled|SCHEDULER_ENABLED|G2G_NOTIFY_EMAIL|outbound_email_enabled|allow_local_unsubscribe)~i',
        'a write to configuration or the environment' => '~(?:\bconfig\s*\(\s*\[|\bConfig::set\b|->set\s*\(\s*[\'"][a-z_.]+[\'"]|\bputenv\s*\(|\$_ENV\b|\$_SERVER\b|\bsetEnv\b)~',
        'an artisan or shell call' => '~\b(?:Artisan::|exec\s*\(|shell_exec|system\s*\(|passthru|proc_open|popen)~',
    ];

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['app/Domain/OutreachGate', 'app/Http/Controllers/Api/Outreach'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $files[] = "{$root}/routes/outreach_gate.php";
        $files[] = "{$root}/config/outreach_gate.php";
        sort($files);

        return $files;
    }

    /** The file with every comment taken out. */
    private function code(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    public function test_the_scan_covers_every_file_of_the_gate(): void
    {
        $names = array_map('basename', $this->files());

        foreach (['OutreachGate.php', 'DraftFactory.php', 'RecipientMap.php', 'CheckStore.php', 'ProductClaims.php', 'ConsentCheck.php', 'UnsubscribeLinkCheck.php', 'OutreachGateController.php', 'OutreachClaimsController.php', 'outreach_gate.php'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} is not scanned");
        }
        $this->assertGreaterThanOrEqual(26, count($names), 'a folder was not walked');
    }

    public function test_nothing_in_the_gate_can_send_schedule_or_switch_anything_on(): void
    {
        $found = [];
        foreach ($this->files() as $file) {
            $code = $this->code($file);
            foreach (self::FORBIDDEN as $what => $pattern) {
                if (preg_match($pattern, $code, $m) === 1) {
                    $found[] = basename($file) . ': ' . $what . ' (' . trim($m[0]) . ')';
                }
            }
        }

        $this->assertSame([], $found, "the gate must not send or enable sending:\n" . implode("\n", $found));
    }

    /**
     * SCHEDULING is a write too: a gate that set `status = approved` or `next_attempt_at` on a message would make the scheduler pick it up without calling a mail
     * function at all. So the gate writes to exactly two tables of its own, and every other write verb, raw statement or model write is a failure.
     */
    private const OWN_TABLES = ['g2g_outreach_gate_checks', 'g2g_product_claims'];

    private const WRITE_VERBS = '~->(?:insert|insertGetId|insertOrIgnore|insertUsing|upsert|update|updateOrInsert|delete|truncate|increment|decrement)\s*\(~';

    private const OTHER_WRITES = '~(?:\bDB::(?:statement|unprepared|affectingStatement|insert|update|delete)\s*\(|::(?:create|forceCreate|insert|upsert|destroy|truncate)\s*\(|->(?:saveQuietly|touch|firstOrCreate|updateOrCreate|createOrFirst|forceDelete|restore|attach|detach|sync)\s*\(|(?<!\$this->store)->save\s*\()~';

    /** @return list<string> the statements of a file's code that write somewhere they should not */
    private function strayWrites(string $code): array
    {
        $found = [];
        foreach (explode(';', $code) as $statement) {
            $tidy = trim((string) preg_replace('/\s+/', ' ', $statement));
            if (preg_match(self::OTHER_WRITES, $statement, $m) === 1) {
                $found[] = trim($m[0]) . ' in: ' . mb_substr($tidy, 0, 90);
            }
            if (preg_match(self::WRITE_VERBS, $statement, $m) === 1) {
                $own = false;
                foreach (self::OWN_TABLES as $table) {
                    $own = $own || str_contains($statement, "DB::table('{$table}')");
                }
                if (! $own) {
                    $found[] = 'a write that is not to one of the gate\'s own tables: ' . mb_substr($tidy, 0, 90);
                }
            }
        }

        return $found;
    }

    public function test_the_gate_writes_only_to_its_own_two_tables(): void
    {
        $found = [];
        foreach ($this->files() as $file) {
            foreach ($this->strayWrites($this->code($file)) as $stray) {
                $found[] = basename($file) . ': ' . $stray;
            }
        }

        $this->assertSame([], $found, "the gate may write only to g2g_outreach_gate_checks and g2g_product_claims:\n" . implode("\n", $found));
    }

    public function test_the_write_scan_itself_can_fail(): void
    {
        $own = "DB::table('g2g_product_claims')->where('id', 1)->update(['status' => 'approved']);";
        $this->assertSame([], $this->strayWrites($own), 'a write to an own table is allowed');
        $this->assertNotSame([], $this->strayWrites("DB::table('gtm_outreach_messages')->where('id', 1)->update(['status' => 'approved']);"), 'scheduling a message');
        $this->assertNotSame([], $this->strayWrites("DB::table('school_setup')->update(['x' => 1]);"), 'a settings row');
        $this->assertNotSame([], $this->strayWrites('$m->update([\'status\' => \'approved\']);'), 'a model update');
        $this->assertNotSame([], $this->strayWrites("DB::statement('update gtm_outreach_messages set status = ?', ['approved']);"), 'a raw statement');
        $this->assertNotSame([], $this->strayWrites('GtmOutreachMessage::create([]);'), 'a model create');
        $this->assertSame(1, preg_match(self::FORBIDDEN['the Mail facade imported, so it can be aliased'], 'use Illuminate\Support\Facades\Mail as Postman;'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a mailer contract, the mail manager or the Symfony mailer'], "app('mail.manager')->mailer()"));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a mailer contract, the mail manager or the Symfony mailer'], 'use Illuminate\Contracts\Mail\Mailer;'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a mailer contract, the mail manager or the Symfony mailer'], 'new \Symfony\Component\Mailer\Mailer($t)'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['an HTTP client other than the facade'], 'new \GuzzleHttp\Client()'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a socket or curl'], 'file_get_contents($url)'));
        $this->assertSame(0, preg_match(self::FORBIDDEN['a socket or curl'], 'the file_get_contents word without a call'), 'a word is not a call');
    }

    public function test_the_only_mention_of_a_mailer_is_the_read_of_what_it_already_publishes(): void
    {
        $mentions = [];
        foreach ($this->files() as $file) {
            $code = $this->code($file);
            if (preg_match_all('~\b(?:Crm)?OutreachMailer\b[^;]*~', $code, $all)) {
                foreach ($all[0] as $hit) {
                    $mentions[basename($file)][] = trim(preg_replace('/\s+/', ' ', $hit));
                }
            }
        }

        $this->assertSame(['ContactStatusCheck.php', 'UnsubscribeLinkCheck.php'], array_keys($mentions), 'only these two files may name a mailer');
        foreach ($mentions['ContactStatusCheck.php'] as $hit) {
            $this->assertMatchesRegularExpression('~^OutreachMailer(?:::BLOCKED_CONTACT\b.*|;?)$~', $hit);
        }
        foreach ($mentions['UnsubscribeLinkCheck.php'] as $hit) {
            // the import lines, and the one call that asks each mailer its own question
            $this->assertMatchesRegularExpression('~^(?:OutreachMailer$|CrmOutreachMailer$|OutreachMailer::class\)->unsubscribeProblem\(\) : \(new CrmOutreachMailer\(\)\)->unsubscribeProblem\(\))~', $hit);
        }
    }

    public function test_the_scan_itself_can_fail(): void
    {
        $this->assertSame(1, preg_match(self::FORBIDDEN['the Mail facade'], '$x = Mail::mailer();'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a send call'], '$mailer->send($m, 1);'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a dispatch'], 'dispatch(new Job());'));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a sending flag'], "config('gtm.outreach.sending_enabled')"));
        $this->assertSame(1, preg_match(self::FORBIDDEN['a write to configuration or the environment'], "config(['crm.email_sending_enabled' => true]);"));
        $this->assertSame(0, preg_match(self::FORBIDDEN['a send call'], 'The text is sent later; resend(); $sender'), 'a word that merely contains "send" is not a call');
    }

    // ---------------------------------------------------------------- where the mailers ask the gate

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> file => [last earlier refusal, the gate call, the claim, the transport] */
    public static function mailers(): array
    {
        return [
            'GTM' => ['app/Domain/Gtm/OutreachMailer.php', "'reply_recorded'", 'Atomic claim: only one caller', 'MailSender::mailer('],
            'CRM' => ['app/Services/Crm/CrmOutreachMailer.php', "'daily_cap'", 'Atomic claim: only one caller', 'MailSender::mailer('],
        ];
    }

    /** @dataProvider mailers */
    #[\PHPUnit\Framework\Attributes\DataProvider('mailers')]
    public function test_each_mailer_asks_the_gate_once_after_its_last_earlier_refusal_and_before_the_claim_and_the_transport(string $file, string $lastRefusal, string $claim, string $transport): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        $this->assertSame(1, substr_count($source, '->forSend('), 'the gate is asked exactly once');
        $gate = strpos($source, '->forSend(');
        // strrpos() answers false when the marker is NOT before the gate, and "gate > false" is true: found by mutation (the gate moved ahead of the sequence checks passed),
        // so the marker must be found, and found before the gate, for this to mean anything.
        $earlier = strrpos(substr($source, 0, $gate), $lastRefusal);
        $this->assertNotFalse($earlier, "the step {$lastRefusal} must come BEFORE the gate");
        $this->assertGreaterThan($earlier, $gate, 'after the last step that was already there');
        $this->assertLessThan(strpos($source, $claim), $gate, 'before the message is claimed');
        $this->assertLessThan(strpos($source, $transport), $gate, 'before the transport is built');
        $this->assertStringContainsString("'gate_blocked'", $source);
    }
}
