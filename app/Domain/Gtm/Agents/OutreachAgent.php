<?php

namespace App\Domain\Gtm\Agents;

use App\Domain\Gtm\GtmAccount;
use App\Domain\Gtm\GtmContact;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a first-touch email or a follow-up sequence. It only DRAFTS: nothing is sent, and a
 * draft with no real signal to open with is refused rather than polished.
 */
final class OutreachAgent extends GtmAgent
{
    private const BLOCKED = ['bounced', 'unsubscribed', 'do_not_contact'];

    public static function slug(): string { return 'gtm-outreach'; }
    public static function name(): string { return 'Outreach Agent'; }
    public static function description(): string { return 'Drafts a first email or a follow-up sequence for a contact you entered, anchored to a sourced buying signal. Produces drafts only - sending needs your explicit approval.'; }
    public static function reads(): array { return ['gtm_accounts', 'gtm_contacts', 'gtm_activities', 'g2g_company_opportunities', 'g2g_product_profiles']; }
    public static function inputs(): array
    {
        return [
            'account_id' => ['type' => 'account', 'required' => true, 'label' => 'Account'],
            'contact_id' => ['type' => 'contact', 'required' => true, 'label' => 'Contact'],
            'mode' => ['type' => 'select', 'required' => true, 'label' => 'Draft', 'options' => ['email', 'sequence']],
        ];
    }

    public function run(int $tenant, ?int $userId, array $input): array
    {
        $account = GtmAccount::where('sub_institute_id', $tenant)->find((int) ($input['account_id'] ?? 0));
        $contact = $account ? GtmContact::where('sub_institute_id', $tenant)->where('account_id', $account->id)->find((int) ($input['contact_id'] ?? 0)) : null;
        if (! $account || ! $contact) {
            throw new \DomainException('Choose an account and one of its contacts.');
        }
        if (in_array($contact->status, self::BLOCKED, true)) {
            throw new \DomainException("{$contact->full_name} is marked '{$contact->status}'. No outreach can be drafted for this contact.");
        }
        $profile = $this->productProfile($tenant);
        $signals = $this->signalsFor($tenant, $account->company_id, 6);
        if ($signals === []) {
            throw new NoDataException("There is no sourced buying signal for {$account->name}, so there is nothing true to open with. Outreach needs a real trigger.");
        }

        $sequence = ($input['mode'] ?? 'email') === 'sequence';
        $records = [
            'product_profile' => $profile,
            'account' => array_filter(['name' => $account->name, 'industry' => $account->industry, 'location' => $account->location], fn ($v) => $v),
            'contact' => array_filter(['name' => $contact->full_name, 'title' => $contact->title, 'role_in_deal' => $contact->role_in_deal], fn ($v) => $v),
            'signals' => $signals,
            'previous_activities' => DB::table('gtm_activities')->where('sub_institute_id', $tenant)->where('account_id', $account->id)->where('type', '!=', 'system')
                ->orderByDesc('occurred_at')->limit(8)->get(['type', 'direction', 'subject', 'occurred_at'])->all(),
        ];
        $urls = array_values(array_unique(array_merge(...array_column($signals, 'source_urls'))));
        $validIds = array_column($signals, 'signal_id');
        $run = $this->ask($tenant, $userId, $sequence ? 'outreach-follow-up-sequence' : 'outreach-first-touch-email', 'outreach_draft', 'contact', (int) $contact->id,
            $records, $urls, ($sequence ? 'Sequence' : 'Email')." draft for {$contact->full_name} at {$account->name}", 2600);

        $data = $run['data'];
        $dropped = 0;
        if ($sequence) {
            $steps = array_values(array_filter((array) ($data['steps'] ?? []), fn ($s) => is_array($s) && trim((string) ($s['body'] ?? '')) !== ''));
            if ($steps === []) {
                throw new \RuntimeException('The model returned no usable sequence steps. Nothing was saved as a draft.');
            }
            $data['steps'] = $steps;
        } else {
            if (trim((string) ($data['body'] ?? '')) === '' || trim((string) ($data['subject'] ?? '')) === '') {
                throw new \RuntimeException('The model returned an empty draft. Nothing was saved as a draft.');
            }
            // A claim stays only if its source is one of this account's real signal URLs.
            $claims = array_values(array_filter((array) ($data['claims'] ?? []), fn ($c) => is_array($c) && in_array($c['source_url'] ?? null, $urls, true)));
            $dropped = count((array) ($data['claims'] ?? [])) - count($claims);
            $data['claims'] = $claims;
            if (! in_array((int) ($data['signal_used'] ?? 0), $validIds, true)) {
                $data['signal_used'] = null;
            }
        }
        $data['is_draft'] = true;
        $data['recipient'] = ['contact_id' => (int) $contact->id, 'name' => $contact->full_name, 'email' => $contact->email];

        return ['result' => $data, 'analysis_id' => $run['analysis_id'], 'provider' => $run['provider'], 'model' => $run['model'], 'playbook' => $run['playbook'],
            'integrity' => ['references_dropped' => $dropped, 'note' => $dropped > 0 ? "Claims whose source was not one of the account's real signals were removed." : null]];
    }
}
