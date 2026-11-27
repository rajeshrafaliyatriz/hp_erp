<?php

namespace App\Domain\Signals\Outreach;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmAudit;
use App\Domain\Gtm\GtmContact;
use App\Domain\Gtm\Outreach\OutreachDrafts;
use App\Domain\Gtm\Outreach\Suppressions;
use App\Domain\Gtm\OutreachMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where a person found for a signal's company meets the send engine. The engine (gtm_outreach_messages, the gate, the approval, the sending switch, the suppression list, the
 * daily cap) only knows GTM contacts, so the first time someone writes to a person this finds or makes the GTM account for their company and the GTM contact for them (a prospect:
 * stage `target`; NEVER a CRM organization or contact) and remembers the pointer on the person (`gtm_contact_id`). Then it writes a DRAFT through the same path as the Outreach screen
 * (OutreachDrafts::forContact), so every safeguard applies unchanged. Nothing here submits, approves or sends anything.
 *
 * Who is refused, and why in words: a person who opted out or was suppressed, one who asked for no email, one with no usable email, an address on the suppression list, and a GTM
 * contact already marked bounced, unsubscribed or do-not-contact. A refusal writes nothing for that person and never stops the others.
 */
final class SignalContactBridge
{
    public function __construct(private readonly OutreachDrafts $drafts)
    {
    }

    /**
     * One draft per person. `{{first_name}}` and `{{company}}` in the subject and body are filled in for each person.
     *
     * @param  list<int>  $personIds
     * @return list<array{person_id: int, ok: bool, message_id: ?int, code: ?string, message: ?string}>
     */
    public function write(int $tenant, int $actor, array $personIds, string $subject, string $body): array
    {
        $results = [];
        foreach (array_values(array_unique(array_map('intval', $personIds))) as $personId) {
            $results[] = ['person_id' => $personId] + $this->one($tenant, $actor, $personId, $subject, $body);
        }

        return $results;
    }

    /**
     * Carries a person's consent to the send engine: someone who opted out, was suppressed or asked for no email is marked do-not-contact on their GTM contact (once they have one), so
     * the Outreach screen and the mailer refuse them too. It never lifts a block: a bounce or an unsubscribe recorded by the engine is not ours to undo.
     */
    public function syncConsent(int $tenant, int $personId): void
    {
        $p = DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $personId)->first(['consent_status', 'suppressed_at', 'email_opt_out', 'gtm_contact_id']);
        if (! $p || ! $p->gtm_contact_id) {
            return;
        }
        $mustBlock = in_array((string) $p->consent_status, OutreachReach::CONSENT_BLOCKING, true) || $p->suppressed_at || (bool) $p->email_opt_out;
        $contact = GtmContact::where('sub_institute_id', $tenant)->find((int) $p->gtm_contact_id);
        if ($mustBlock && $contact && ! in_array($contact->status, OutreachMailer::BLOCKED_CONTACT, true)) {
            $contact->forceFill(['status' => 'do_not_contact'])->save();
        }
    }

    /** @return array{ok: bool, message_id: ?int, code: ?string, message: ?string} */
    private function one(int $tenant, int $actor, int $personId, string $subject, string $body): array
    {
        $refuse = fn (string $code, string $message) => ['ok' => false, 'message_id' => null, 'code' => $code, 'message' => $message];
        $p = DB::table('g2g_signal_contacts as p')->join('g2g_companies as c', 'c.id', '=', 'p.company_id')->where('p.sub_institute_id', $tenant)->where('p.id', $personId)
            ->whereNull('p.deleted_at')->whereNull('c.deleted_at')->first(['p.*', 'c.name as company_name']);
        if (! $p) {
            return $refuse('not_found', 'This person could not be found.');
        }
        $label = trim((string) $p->full_name) ?: "{$p->company_name} (company line)";
        $reach = OutreachReach::forPerson($p);
        if ($reach === OutreachReach::BLOCKED) {
            return $refuse('blocked', "{$label}: " . OutreachReach::whyBlocked($p));
        }
        if ((bool) $p->email_opt_out && OutreachReach::hasEmail((array) $p)) {
            return $refuse('email_opt_out', "{$label} asked for no email.");
        }
        $email = OutreachReach::emailOf($p);
        if ($email === null) {
            return $refuse('no_email', "{$label} has no email address yet. Add one, or run Find contacts.");
        }

        // Checked BEFORE anything is made, so a person on the suppression list never gets a GTM contact either.
        if ($block = app(Suppressions::class)->blocking($tenant, $email)) {
            return $refuse('suppressed', "{$label}: this address is on the suppression list ({$block['kind']} {$block['value']}, {$block['reason']}), so no message can be written for it.");
        }

        // By hand, not DB::transaction: a refusal is an ANSWER, not an error, and it must still take back the account and contact made on the way to it (nothing is written for a refused person).
        DB::beginTransaction();
        try {
            $company = DB::table('g2g_companies')->where('sub_institute_id', $tenant)->where('id', $p->company_id)->first();
            $account = $this->accountFor($tenant, $actor, $company);
            $contact = $this->contactFor($tenant, $actor, $p, $company, $account, $email);
            $made = $this->drafts->forContact($tenant, $contact, $this->fill($subject, $p, $company->name), $this->fill($body, $p, $company->name), $actor, 'signals_outreach');
            if (! $made['ok']) {
                DB::rollBack();

                return $refuse($made['code'], "{$label}: {$made['message']}");
            }
            DB::commit();

            return ['ok' => true, 'message_id' => (int) $made['message']->id, 'code' => null, 'message' => null];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::warning('signals.outreach.draft_failed', ['person' => $personId, 'error' => class_basename($e), 'detail' => $e->getMessage()]);

            return $refuse('error', "{$label}: the draft could not be saved. Nothing was written for this person.");
        }
    }

    /** The prospect account of the company: the one already linked to it, else one with its domain (adopted), else a new `target`. */
    private function accountFor(int $tenant, int $actor, object $company): GtmAccount
    {
        $a = GtmAccount::where('sub_institute_id', $tenant)->where('company_id', $company->id)->first();
        if ($a) {
            return $a;
        }
        if ($company->domain) {
            $a = GtmAccount::withTrashed()->where('sub_institute_id', $tenant)->where('domain', $company->domain)->first();
            if ($a) {
                if ($a->trashed()) {
                    $a->restore();
                }
                if (! $a->company_id) {
                    $a->forceFill(['company_id' => $company->id])->save();
                }

                return $a;
            }
        }

        return GtmAccount::create([
            'sub_institute_id' => $tenant, 'company_id' => $company->id, 'name' => mb_substr((string) $company->name, 0, 191), 'domain' => $company->domain ?: null,
            'website' => $company->website ? mb_substr((string) $company->website, 0, 255) : null, 'industry' => $company->industry, 'location' => $company->location, 'stage' => 'target',
            'owner_user_id' => $company->assigned_to ?: null, 'source' => ($company->source ?? 'found') === 'manual' ? 'manual' : 'research', 'created_by' => $actor,
        ]);
    }

    /** The prospect's GTM contact: the one already remembered, else one of the account with the same email, else a new one; kept in step with the person's current details. */
    private function contactFor(int $tenant, int $actor, object $p, object $company, GtmAccount $account, string $email): GtmContact
    {
        $name = mb_substr(trim((string) $p->full_name) ?: "{$company->name} (company line)", 0, 191);
        $facts = ['full_name' => $name, 'title' => $p->designation ? mb_substr((string) $p->designation, 0, 191) : null, 'email' => $email, 'phone' => $p->phone ?: $p->mobile,
            'linkedin_url' => $p->linkedin_url ? mb_substr((string) $p->linkedin_url, 0, 255) : null];
        $c = $p->gtm_contact_id ? GtmContact::where('sub_institute_id', $tenant)->find((int) $p->gtm_contact_id) : null;
        $c ??= GtmContact::where('sub_institute_id', $tenant)->where('account_id', $account->id)->where('email', $email)->first();
        if ($c) {
            $c->forceFill($facts)->save();
        } else {
            $c = GtmContact::create($facts + ['sub_institute_id' => $tenant, 'account_id' => $account->id, 'status' => 'active', 'source' => $p->source === 'manual' ? 'manual' : 'research', 'created_by' => $actor]);
        }
        if ((int) $p->gtm_contact_id !== (int) $c->id) {
            DB::table('g2g_signal_contacts')->where('sub_institute_id', $tenant)->where('id', $p->id)->update(['gtm_contact_id' => $c->id, 'updated_at' => now()]);
        }

        return $c;
    }

    /** What a token becomes for one person: the first word of the name (or "there"), and the company's name. Mirrors the screen's preview. */
    private function fill(string $text, object $p, string $company): string
    {
        $first = preg_split('/\s+/u', trim((string) $p->full_name))[0] ?? '';

        return preg_replace_callback('/\{\{\s*(first_name|company)\s*\}\}/i', fn ($m) => strtolower($m[1]) === 'company' ? $company : ($first !== '' ? $first : 'there'), $text) ?? $text;
    }
}
