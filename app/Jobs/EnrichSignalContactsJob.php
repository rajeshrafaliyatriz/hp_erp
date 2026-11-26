<?php

namespace App\Jobs;

use App\Domain\Signals\Opportunities\Company;
use App\Domain\Signals\Opportunities\CompanyOpportunity;
use App\Domain\Signals\Opportunities\ContactResearcher;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\Opportunities\SignalContactStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Looks up public contacts for the organisation behind ONE signal, after the signal exists. It runs on its own, on the signals queue:
 * signal generation never waits for it and never fails because of it, and it changes nothing about the signal itself (status, qualification,
 * score, visibility). Any failure is logged without secrets and leaves the signal exactly as it was.
 */
class EnrichSignalContactsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(public int $tenantId, public int $opportunityId, public bool $force = false)
    {
        $this->onQueue((string) config('signals.queue', 'signals'));
    }

    /** Contact research is switched on and a web-search provider is configured (otherwise nothing is searched, and the screen says so). */
    public static function isAvailable(): bool
    {
        try {
            // Automated tests never reach a real search provider by accident: a credential in the environment must not be spent by a test that merely
            // creates a signal. A test that exercises the lookup turns this on itself, with the HTTP layer faked.
            if (app()->runningUnitTests() && ! config('signals_connectors.contact_research.allow_in_tests', false)) {
                return false;
            }

            return (bool) config('signals_connectors.contact_research.enabled', true) && SearchProviderFactory::make()?->isConfigured() === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Queue the lookup if contact research is on and a search provider is configured. Never throws: a signal is already saved when this runs. */
    public static function dispatchFor(int $tenantId, int $opportunityId, bool $force = false): bool
    {
        try {
            if (! static::isAvailable()) {
                return false;
            }
            // After the signal's own transaction commits (the importer saves signals inside one): the job must never run, or hold anything open, while a signal is being saved.
            static::dispatch($tenantId, $opportunityId, $force)->afterCommit();

            return true;
        } catch (\Throwable $e) {
            Log::warning('signals.contact_research_dispatch_failed', ['opportunity' => $opportunityId, 'error' => class_basename($e)]);

            return false;
        }
    }

    public function handle(): void
    {
        try {
            $opp = CompanyOpportunity::where('sub_institute_id', $this->tenantId)->whereKey($this->opportunityId)->first();
            $company = $opp?->company_id ? Company::where('sub_institute_id', $this->tenantId)->whereKey($opp->company_id)->first() : null;
            if (! $opp || ! $company) {
                return;
            }
            // One lookup per organisation at a time: several signals of the same organisation queue several jobs, and two running together would both
            // see "no contacts yet" and both store them. The loser simply leaves; the winner's result serves every signal of the organisation.
            try {
                $lock = Cache::lock("signals:contact-research-lock:{$this->tenantId}:{$company->id}", 300);
                $held = $lock->get();
            } catch (\Throwable) {
                $lock = null;   // this cache store cannot make locks: run without one (the stored-contact de-duplication still prevents repeats)
                $held = true;
            }
            if (! $held) {
                return;
            }
            try {
                $this->lookup($opp, $company);
            } finally {
                try {
                    $lock?->release();
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable $e) {
            Log::warning('signals.contact_research_failed', ['tenant' => $this->tenantId, 'opportunity' => $this->opportunityId, 'error' => class_basename($e)]);
        }
    }

    private function lookup(CompanyOpportunity $opp, Company $company): void
    {
        $last = SignalContactStore::lastSearch($this->tenantId, (int) $company->id);
        if (! $this->force && $last !== null) {
            return;   // this organisation was searched recently
        }
        $provider = SearchProviderFactory::make();
        if ($provider === null || ! $provider->isConfigured()) {
            return;
        }

        $roles = array_values(array_filter(array_merge(
            $opp->likely_stakeholder ? [(string) $opp->likely_stakeholder] : [],
            (array) config('signals_connectors.contact_research.roles', ['Principal', 'Director', 'Founder'])
        )));
        $researcher = new ContactResearcher($provider, (int) config('signals_connectors.contact_research.max_searches', 6));
        $contacts = $researcher->research([
            'company_name' => (string) $company->name, 'website' => $company->website, 'location' => $company->location, 'roles' => $roles,
        ]);
        $saved = SignalContactStore::save($this->tenantId, (int) $company->id, (int) $opp->id, $contacts);

        // A search that could not run at all is retried next time; one that ran and found nothing is remembered so credits are not spent again.
        $couldNotSearch = $contacts === [] && $researcher->failures > 0 && $researcher->failures >= $researcher->searchesUsed();
        if (! $couldNotSearch) {
            SignalContactStore::markSearched($this->tenantId, (int) $company->id, $saved > 0 ? 'found' : 'none');
        }
        Log::info('signals.contact_research', ['tenant' => $this->tenantId, 'opportunity' => $opp->id, 'searches' => $researcher->searchesUsed(), 'failed' => $researcher->failures, 'found' => count($contacts), 'saved' => $saved]);
    }
}
