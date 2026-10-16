<?php

namespace App\Domain\Signals\Icp;

use App\Domain\Signals\Opportunities\ProductProfile;
use App\Domain\Signals\Opportunities\ProductProfileService;
use App\Domain\Signals\Opportunities\ResearchRun;
use App\Domain\Signals\Opportunities\SearchProviderFactory;
use App\Domain\Signals\SignalRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Whether company research will actually run for an organisation, in one place: its ICPs, whether the daily schedule is
 * on, when the next run is due, whether a search and an AI provider are configured, and how the last run went.
 *
 * It reports yes or no for providers and NEVER reads or returns a key. Used by `signals:diagnose`.
 */
final class SignalsTenantOverview
{
    public function __construct(private readonly ProductProfileService $profiles, private readonly SignalRunner $signals)
    {
    }

    /** @return list<int> organisations that have a product profile or an ICP */
    public function tenants(): array
    {
        $ids = ProductProfile::query()->pluck('sub_institute_id')->all();
        if (Schema::hasTable('g2g_icp_profiles')) {
            $ids = array_merge($ids, IcpProfile::query()->distinct()->pluck('sub_institute_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return array<string, mixed> */
    public function forTenant(int $tenantId): array
    {
        $profile = ProductProfile::where('sub_institute_id', $tenantId)->first();
        $icps = Schema::hasTable('g2g_icp_profiles') ? IcpProfile::where('sub_institute_id', $tenantId)->orderByDesc('is_default')->orderBy('id')->get() : collect();
        $active = $icps->where('status', 'active');
        $scheduleOn = (bool) ($profile?->research_enabled);

        // ICPs exist but none is active: the schedule has nothing to run (it never falls back to generic research).
        $blockedByIcp = $icps->isNotEmpty() && $active->isEmpty();
        $next = ($profile && $scheduleOn && ! $blockedByIcp) ? $this->profiles->nextRun($profile) : null;

        $search = SearchProviderFactory::make();
        $last = ResearchRun::where('sub_institute_id', $tenantId)->orderByDesc('id')->first();
        $tick = Cache::get('signals:scheduler:last_tick');

        return [
            'tenant' => $tenantId,
            'active_icps' => $active->pluck('key')->values()->all(),
            'other_icps' => ['draft' => $icps->where('status', 'draft')->count(), 'archived' => $icps->where('status', 'archived')->count()],
            'has_product_profile' => $profile !== null,
            'schedule_on' => $scheduleOn,
            'schedule_time' => $profile ? $this->profiles->scheduleTime($profile) : null,
            'blocked_by_inactive_icp' => $blockedByIcp,
            'next_run_at' => $next?->toIso8601String(),
            'timezone' => (string) config('signals.timezone'),
            'scheduler_last_tick' => $tick ? (string) $tick : null,
            'search_provider_configured' => $search !== null && $search->isConfigured(),
            'ai_provider_configured' => $this->signals->aiConfigured($tenantId),
            'last_run' => $last ? [
                'id' => (int) $last->id, 'status' => $last->status, 'trigger' => $last->trigger,
                'started_at' => $last->started_at?->toIso8601String(), 'error_code' => $last->error_code,
            ] : null,
        ];
    }

    /** One short, readable block per organisation. Yes/no only for providers. @param array<string, mixed> $o @return list<string> */
    public function lines(array $o): array
    {
        $yn = fn (bool $v) => $v ? 'yes' : 'NO';
        $icps = $o['active_icps'] === [] ? 'none active' : implode(', ', $o['active_icps']);
        $other = $o['other_icps'];
        if ($other['draft'] + $other['archived'] > 0) {
            $icps .= " ({$other['draft']} draft, {$other['archived']} archived)";
        }

        $schedule = ! $o['has_product_profile'] ? 'no product profile (research cannot be scheduled)'
            : ($o['schedule_on'] ? "ON, daily at {$o['schedule_time']} {$o['timezone']}" : 'OFF (research_enabled is off)');
        $next = $o['next_run_at'] ?? ($o['blocked_by_inactive_icp'] ? 'none: ICPs exist but none is active, so no scheduled run happens' : ($o['schedule_on'] ? 'not scheduled' : 'none (schedule is off)'));
        $last = $o['last_run'] ? "#{$o['last_run']['id']} {$o['last_run']['status']} ({$o['last_run']['trigger']}, " . ($o['last_run']['started_at'] ?? '?') . ')' . ($o['last_run']['error_code'] ? " [{$o['last_run']['error_code']}]" : '') : 'no run yet';

        return [
            "Organisation {$o['tenant']}",
            "  Active ICPs:                {$icps}",
            "  Schedule:                   {$schedule}",
            "  Next run:                   {$next}",
            '  Scheduler last ran:         ' . ($o['scheduler_last_tick'] ?? 'never (is `schedule:work` or a cron entry running?)'),
            '  Search provider configured: ' . $yn($o['search_provider_configured']),
            '  AI provider configured:     ' . $yn($o['ai_provider_configured']),
            "  Last run:                   {$last}",
        ];
    }
}
