<?php

namespace App\Domain\OutreachGate;

use App\Domain\Facts\ConsentGate;
use App\Domain\Gtm\Outreach\Suppressions;
use App\Domain\OutreachGate\Checks\BannedPhraseCheck;
use App\Domain\OutreachGate\Checks\ConsentCheck;
use App\Domain\OutreachGate\Checks\ContactStatusCheck;
use App\Domain\OutreachGate\Checks\LengthCheck;
use App\Domain\OutreachGate\Checks\LinkCountCheck;
use App\Domain\OutreachGate\Checks\PlaceholderCheck;
use App\Domain\OutreachGate\Checks\RecipientCheck;
use App\Domain\OutreachGate\Checks\SuppressionCheck;
use App\Domain\OutreachGate\Checks\UnapprovedClaimCheck;
use App\Domain\OutreachGate\Checks\UnsubscribeLinkCheck;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE OUTREACH GATE (slice 10 of the agentic CRM, docs/agentic-crm/slices/S10-outreach-gate.md): one deterministic list of checks that both mailers ask as one more step
 * of their refusal ladder, and that a screen can ask on its own to preview a draft.
 *
 *   THE GATE IS A REASON NOT TO SEND, NEVER A SENDER. Nothing in this namespace sends, queues, schedules or switches anything on: it reads, it answers, and it records the
 *   answer (CheckStore). A test scans this namespace and fails on any mail, HTTP, notification or queue call, and on any mention of a sending flag.
 *
 *   FAIL CLOSED. A message passes only if EVERY check passed. A check that throws counts as failed; a gate that cannot even be set up blocks the message (forSend() never
 *   throws and never passes by accident); a storage failure changes nothing about the answer.
 *
 * No model call: every rule is a pattern, a count or a lookup, so the same draft gets the same answer twice.
 */
class OutreachGate
{
    public function __construct(
        private readonly CheckStore $store,
        private readonly ClaimBook $book,
        private readonly ConsentGate $consent,
        private readonly Suppressions $suppressions,
    ) {
    }

    /** @return list<Check> in the order they run and are shown */
    public function checks(RecipientMap $map): array
    {
        $c = (array) config('outreach_gate');

        return [
            new BannedPhraseCheck((array) ($c['banned_phrases'] ?? [])),
            new LengthCheck((int) ($c['max_subject_chars'] ?? 150), (int) ($c['max_body_chars'] ?? 1500)),
            new LinkCountCheck((int) ($c['max_links'] ?? 1), (array) ($c['unsubscribe_markers'] ?? ['unsubscribe']), (array) ($c['link_tlds'] ?? [])),
            new PlaceholderCheck((array) ($c['placeholder_patterns'] ?? [])),
            new UnapprovedClaimCheck($this->book, (array) ($c['claim_markers'] ?? [])),
            new RecipientCheck($map),
            new ConsentCheck($map, $this->consent),
            new SuppressionCheck($map, $this->suppressions),
            new ContactStatusCheck($map),
            new UnsubscribeLinkCheck(),
        ];
    }

    /** @return array<string, string> check key => the label a person reads */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->checks(new RecipientMap()) as $check) {
            $labels[$check->key()] = $check->label();
        }

        return $labels;
    }

    /**
     * Run every check now. With $store the set is recorded (a re-check adds a set). Never sends anything. Does not throw for a failing check.
     */
    public function evaluate(Draft $draft, bool $store = true): GateResult
    {
        $map = new RecipientMap();
        $results = [];
        try {
            $checks = $this->checks($map);
        } catch (Throwable $e) {
            Log::warning('Outreach gate could not be set up', ['error' => $e->getMessage()]);
            $checks = [];
            $results[] = CheckResult::fail('gate', 'Gate ready', 'The outreach checks could not be set up, so the message is held back.');
        }

        foreach ($checks as $check) {
            try {
                $results[] = $check->run($draft);
            } catch (Throwable $e) {
                Log::warning('Outreach gate check failed to run', ['check' => $check->key(), 'error' => $e->getMessage()]);
                $results[] = CheckResult::fail($check->key(), $check->label(), 'This check could not run, so the message is held back.');
            }
        }

        $result = new GateResult((string) Str::uuid(), $draft->table, $draft->id, $draft->tenant, $results, now());
        if ($store) {
            try {
                $this->store->save($result);
            } catch (Throwable $e) {
                Log::warning('Outreach gate result could not be stored', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** The newest stored set of a message, with the labels a person reads; null when it was never evaluated. */
    public function latest(int $tenant, string $table, int $messageId): ?GateResult
    {
        return $this->store->latest($tenant, $table, $messageId, $this->labels());
    }

    /**
     * What a mailer asks right before it would hand a message to the transport. NEVER THROWS and never answers "pass" by accident: any failure inside the gate is a block.
     *
     * The draft may be handed over as a closure, so that building it from the message is inside the same guard.
     *
     * @param  Draft|\Closure(): Draft  $draft
     * @return array{passed: bool, message: string, checks: list<array{key: string, passed: bool, detail: string}>}
     */
    public function forSend(Draft|\Closure $draft): array
    {
        try {
            $result = $this->evaluate($draft instanceof \Closure ? $draft() : $draft);
        } catch (Throwable $e) {
            Log::warning('Outreach gate failed', ['error' => $e->getMessage()]);

            return $this->blocked([CheckResult::fail('gate', 'Gate ready', 'The outreach checks could not run, so the message is held back.')]);
        }

        return $result->passed() ? ['passed' => true, 'message' => '', 'checks' => []] : $this->blocked($result->failed() ?: [CheckResult::fail('gate', 'Gate ready', 'No check ran, so the message is held back.')]);
    }

    /**
     * @param  list<CheckResult>  $failed
     * @return array{passed: bool, message: string, checks: list<array{key: string, passed: bool, detail: string}>}
     */
    private function blocked(array $failed): array
    {
        $message = 'The outreach checks stopped this message. ' . implode(' ', array_map(fn (CheckResult $c) => "{$c->label}: {$c->detail}", $failed)) . ' Nothing was sent.';
        if (mb_strlen($message) > 380) {
            $message = rtrim(mb_substr($message, 0, 379)) . '…';
        }

        return [
            'passed' => false,
            'message' => $message,
            'checks' => array_map(fn (CheckResult $c) => ['key' => $c->key, 'passed' => false, 'detail' => $c->detail], $failed),
        ];
    }
}
