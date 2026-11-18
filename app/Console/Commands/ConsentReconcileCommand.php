<?php

namespace App\Console\Commands;

use App\Domain\Facts\ConsentRecorder;
use App\Domain\Facts\DryRunRollback;
use App\Domain\Gtm\OutreachMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the platform's four consent places into agreement for one organisation (slice 4, defect D7), once, for what was recorded BEFORE the unsubscribe,
 * complaint and bounce handlers learned to write through (App\Domain\Facts\ConsentRecorder):
 *
 *   php artisan consent:reconcile --tenant=6                 DRY RUN (the default): prints what it WOULD change and writes nothing
 *   php artisan consent:reconcile --tenant=6 --apply         does it, in one transaction for the organisation
 *   php artisan consent:reconcile --tenant=6 --out=path.txt  also saves the printed summary to a file (for _evidence/)
 *
 * What it does, and the only things it does (DB-RULES: a data update is its own command, dry by default, one transaction per organisation, never overwrites,
 * prints counts, and a second run changes nothing):
 *   A. every contact, lead and organisation that is opted out (`email_opt_out`, `consent_status = opted_out`) or suppressed (`suppressed_at`, `consent_status =
 *      suppressed`) is carried to everything that shares its address: the other records, an opted-out email preference row, the suppression list, the outreach
 *      contacts;
 *   B. every address on the suppression list, every outreach contact that is unsubscribed, bounced or do_not_contact, and every record with an opted-out email
 *      preference is carried to the CRM records that share it;
 *   C. a lead that was converted and has an opt-out its contact lacks: the contact gets it.
 * It only ESCALATES. It never clears an opt-out, never writes an opt-in, never touches a row that already says what it should, and a whole-DOMAIN suppression
 * entry is only reported (the gate reads it directly; rewriting every address of a domain would be too broad a change to make unasked).
 *
 * The dry run is the real run rolled back, so what it reports is exactly what `--apply` would do. Nothing is ever sent.
 */
class ConsentReconcileCommand extends Command
{
    protected $signature = 'consent:reconcile {--tenant= : the organisation (sub_institute_id), required} {--apply : write the changes (default: a dry run)} {--out= : also save the summary to this file}';

    protected $description = 'Make the CRM consent flags, the consent preferences, the suppression list and the outreach contacts agree for one organisation (dry run unless --apply)';

    private const TABLES = ['contact' => 'crm_contacts', 'organization' => 'crm_organizations', 'lead' => 'crm_leads'];

    /** @var array<string, true> the records seen so far in this run, so a record met again in a later round is counted once */
    private array $examined = [];

    public function handle(ConsentRecorder $recorder): int
    {
        $tenant = $this->option('tenant');
        if ($tenant === null || ! ctype_digit((string) $tenant) || (int) $tenant <= 0) {
            $this->error('--tenant=<organisation id> is required (a positive number).');

            return self::FAILURE;
        }
        $tenant = (int) $tenant;
        $apply = (bool) $this->option('apply');
        $this->examined = [];

        $counts = ['records_examined' => 0, 'records_changed' => 0, 'preferences_written' => 0, 'suppressions_added' => 0, 'outreach_contacts_updated' => 0, 'converted_contacts_changed' => 0];
        $domainEntries = 0;

        try {
            DB::transaction(function () use ($recorder, $tenant, $apply, &$counts, &$domainEntries) {
                // Run the three passes until a whole round changes nothing: a record the lists flag can be the lead a conversion carries over, and the
                // contact that receives it then has an address to carry on to the lists. Three rounds always reach that point (record, list, conversion),
                // and the bound keeps a bug from looping. This is also what makes a SECOND run report nothing to do.
                for ($round = 0; $round < 4; $round++) {
                    $before = $this->changes($counts);
                    $domainEntries = 0;
                    $this->passRecords($recorder, $tenant, $counts);
                    $this->passLists($recorder, $tenant, $counts, $domainEntries);
                    $this->passConverted($recorder, $tenant, $counts);
                    if ($this->changes($counts) === $before) {
                        break;
                    }
                }
                if (! $apply) {
                    throw new DryRunRollback();
                }
            });
        } catch (DryRunRollback) {
            // the dry run is the real run, rolled back
        }

        $lines = [
            'consent:reconcile for organisation ' . $tenant . ' on connection ' . DB::getDefaultConnection() . ' (' . DB::connection()->getDatabaseName() . ')',
            $apply ? 'MODE: APPLIED (committed in one transaction)' : 'MODE: DRY RUN (nothing was written)',
            '',
            ($apply ? 'changed' : 'would change') . ':',
            '  records flagged or suppressed ........ ' . $counts['records_changed'] . '  (of ' . $counts['records_examined'] . ' opted-out or suppressed records examined)',
            '  email preference rows written ........ ' . $counts['preferences_written'],
            '  suppression-list entries added ....... ' . $counts['suppressions_added'],
            '  outreach contacts marked ............. ' . $counts['outreach_contacts_updated'],
            '  converted-lead contacts brought level  ' . $counts['converted_contacts_changed'],
            'only reported, never rewritten:',
            '  whole-domain suppression entries ..... ' . $domainEntries . '  (the gate reads them directly)',
            '',
            $this->tail($counts, $apply),
        ];
        foreach ($lines as $line) {
            $this->line($line);
        }
        if (is_string($this->option('out')) && $this->option('out') !== '') {
            File::ensureDirectoryExists(dirname((string) $this->option('out')));
            File::put((string) $this->option('out'), implode(PHP_EOL, $lines) . PHP_EOL);
        }

        return self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function changes(array $counts): int
    {
        return $counts['records_changed'] + $counts['preferences_written'] + $counts['suppressions_added'] + $counts['outreach_contacts_updated'] + $counts['converted_contacts_changed'];
    }

    private function tail(array $counts, bool $apply): string
    {
        $total = $this->changes($counts);

        return $total === 0 ? 'Nothing to do: the four places already agree.' : ($apply ? 'Done. Run it again: it should report nothing to do.' : 'Run again with --apply to do this.');
    }

    /** A. Records that are opted out or suppressed, carried to everything that shares their address. */
    private function passRecords(ConsentRecorder $recorder, int $tenant, array &$counts): void
    {
        foreach (self::TABLES as $type => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table($table)->where('sub_institute_id', $tenant)->whereNull('deleted_at');
            $query->where(function ($w) use ($table) {
                $w->where('email_opt_out', 1);
                if (Schema::hasColumn($table, 'consent_status')) {
                    $w->orWhereIn('consent_status', ['opted_out', 'suppressed']);
                }
                if (Schema::hasColumn($table, 'suppressed_at')) {
                    $w->orWhereNotNull('suppressed_at');
                }
            });
            foreach ($query->orderBy('id')->get() as $row) {
                $this->examined["{$type}:{$row->id}"] = true;
                $counts['records_examined'] = count($this->examined);
                $optedOut = (int) ($row->email_opt_out ?? 0) === 1 || strtolower((string) ($row->consent_status ?? '')) === 'opted_out';
                $reason = $optedOut ? 'unsubscribed' : $this->suppressionReason((string) ($row->suppressed_reason ?? ''));
                $this->add($counts, $recorder->optOut($tenant, $type, (int) $row->id, $reason, 'reconcile', null, 'Reconciled from the record\'s own flags'));
            }
        }
    }

    /** B. The suppression list, the outreach contacts and the preference rows, carried to the CRM records that share them. */
    private function passLists(ConsentRecorder $recorder, int $tenant, array &$counts, int &$domainEntries): void
    {
        if (Schema::hasTable('gtm_suppressions')) {
            foreach (DB::table('gtm_suppressions')->where('sub_institute_id', $tenant)->orderBy('id')->get() as $entry) {
                if ($entry->kind !== 'email') {
                    $domainEntries++;

                    continue;
                }
                $this->add($counts, $recorder->optOutAddress($tenant, (string) $entry->value, $this->listReason((string) $entry->reason), 'reconcile', null, 'Reconciled from the suppression list'));
            }
        }
        if (Schema::hasTable('gtm_contacts')) {
            foreach (DB::table('gtm_contacts')->where('sub_institute_id', $tenant)->whereNull('deleted_at')->whereIn('status', OutreachMailer::BLOCKED_CONTACT)->whereNotNull('email')->orderBy('id')->get() as $contact) {
                $reason = match ($contact->status) {
                    'bounced' => 'hard_bounce',
                    'do_not_contact' => 'manual',
                    default => 'unsubscribed',
                };
                $this->add($counts, $recorder->optOutAddress($tenant, (string) $contact->email, $reason, 'reconcile', null, 'Reconciled from an outreach contact'));
            }
        }
        if (Schema::hasTable('crm_consent_preferences')) {
            foreach (DB::table('crm_consent_preferences')->where('channel', 'email')->where('status', 'opted_out')->where(fn ($w) => $w->where('sub_institute_id', $tenant)->orWhereNull('sub_institute_id'))->orderBy('id')->get() as $pref) {
                if (isset(self::TABLES[$pref->entity_type])) {
                    $this->add($counts, $recorder->optOut($tenant, (string) $pref->entity_type, (int) $pref->entity_id, 'unsubscribed', 'reconcile', null, 'Reconciled from an email preference'));
                }
            }
        }
    }

    /** C. A converted lead's consent, carried to its contact. */
    private function passConverted(ConsentRecorder $recorder, int $tenant, array &$counts): void
    {
        if (! Schema::hasTable('crm_leads') || ! Schema::hasColumn('crm_leads', 'converted_contact_id')) {
            return;
        }
        foreach (DB::table('crm_leads')->where('sub_institute_id', $tenant)->where('converted', 1)->whereNotNull('converted_contact_id')->orderBy('id')->get(['id', 'converted_contact_id']) as $lead) {
            $counts['converted_contacts_changed'] += $recorder->carryOver($tenant, 'lead', (int) $lead->id, 'contact', (int) $lead->converted_contact_id);
        }
    }

    /** @param array{records: int, preferences: int, suppression: bool, outreach_contacts: int} $result */
    private function add(array &$counts, array $result): void
    {
        $counts['records_changed'] += $result['records'];
        $counts['preferences_written'] += $result['preferences'];
        $counts['suppressions_added'] += $result['suppression'] ? 1 : 0;
        $counts['outreach_contacts_updated'] += $result['outreach_contacts'];
    }

    /** The reason a suppressed record carries, in the vocabulary of the suppression list. */
    private function suppressionReason(string $why): string
    {
        $why = strtolower(trim($why));

        return in_array($why, ConsentRecorder::BOUNCE_REASONS, true) ? $why : (str_contains($why, 'bounc') ? 'hard_bounce' : 'manual');
    }

    private function listReason(string $reason): string
    {
        return in_array($reason, array_merge(ConsentRecorder::OPT_OUT_REASONS, ConsentRecorder::BOUNCE_REASONS), true) ? $reason : 'manual';
    }
}
