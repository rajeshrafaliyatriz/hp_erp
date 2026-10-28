<?php

namespace App\Http\Controllers\Api\Signals;

use App\Domain\Signals\Icp\IcpOpportunityComparator;
use App\Domain\Signals\Icp\IcpProfile;
use App\Domain\Signals\Icp\TargetAccount;
use App\Domain\Signals\Products\ProductCatalog;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One stored opportunity compared with its own product's ICP, criterion by criterion. Read-only: it changes no opportunity, starts no research,
 * calls no provider and never decides qualification (the server's gate did that when the signal was stored). The organisation comes from the
 * token, so an opportunity of another organisation is simply "not found".
 */
class OpportunityIcpComparisonController extends Controller
{
    use ResolvesApiIdentity;

    public function show(Request $request, int $id): JsonResponse
    {
        if (! is_array($identity = $this->resolveApiIdentity($request))) {
            return $identity;
        }
        $tenantId = $identity['sub_institute_id'];

        $row = DB::table('g2g_company_opportunities as o')
            ->leftJoin('g2g_companies as c', function ($join) use ($tenantId) {
                $join->on('c.id', '=', 'o.company_id')->where('c.sub_institute_id', '=', $tenantId);
            })
            ->where('o.sub_institute_id', $tenantId)->where('o.id', $id)
            ->select('o.*', 'c.name as company_name', 'c.website as company_website')
            ->first();
        if ($row === null) {
            return response()->json(['status' => 0, 'message' => 'Opportunity not found'], 404);
        }

        // The product is the one stored on the record (business_fit), as the product filter uses it: never guessed from a title.
        $key = null;
        foreach (ProductCatalog::definitionsFor($tenantId) as $k => $d) {
            if ($row->business_fit !== null && $d['business_fit'] === $row->business_fit) {
                $key = $k;
                break;
            }
        }
        $stored = $this->stored($row);
        if ($key === null) {
            return response()->json(['status' => 1, 'data' => ['available' => false, 'reason' => 'This signal is not assigned to a product, so there is no ICP to compare it with.', 'product' => null, 'icp' => null, 'stored_result' => $stored, 'evidence' => $this->evidence($row), 'criteria' => [], 'summary' => null, 'notes' => []]]);
        }

        $catalog = new ProductCatalog();
        $found = $catalog->icpFor($tenantId, $key);
        $icp = $found['icp'];
        if ($icp['config'] === null) {
            return response()->json(['status' => 1, 'data' => ['available' => false, 'reason' => $found['def']['label'] . ' has no active ICP, so there is nothing to compare this signal with.', 'product' => ['key' => $key, 'label' => $found['def']['label']], 'icp' => null, 'stored_result' => $stored, 'evidence' => $this->evidence($row), 'criteria' => [], 'summary' => null, 'notes' => []]]);
        }

        $profile = $icp['profile_id'] !== null ? IcpProfile::where('sub_institute_id', $tenantId)->where('id', $icp['profile_id'])->first() : null;
        $account = $this->account($tenantId, (int) $row->company_id, $icp['pack_key']);
        $evidence = $this->evidence($row);
        $result = (new IcpOpportunityComparator())->compare($icp['config'], $account, [
            'company_name' => $row->company_name, 'observed_event' => $row->observed_event,
            'source' => ['label' => $evidence['source_title'], 'url' => $evidence['source_url']],
        ]);

        return response()->json(['status' => 1, 'data' => [
            'available' => true,
            'product' => ['key' => $key, 'label' => $found['def']['label'], 'custom' => ! empty($found['def']['custom'])],
            'icp' => [
                'profile_id' => $icp['profile_id'], 'name' => $profile?->name ?? ($icp['config']['label'] ?? null), 'version' => $profile ? (int) $profile->version : null,
                'status' => $profile?->status, 'source' => $icp['source'],
            ],
            'stored_result' => $stored,
            'evidence' => $evidence,
            'account_linked' => $account !== null,
        ] + $result]);
    }

    /** What the server decided when it stored the signal. Shown as it is; the comparison never changes it. @return array<string, mixed> */
    private function stored(object $row): array
    {
        $assessment = $this->assessment($row);

        return [
            'qualification' => $row->qualification ?? null, 'claim_level' => $row->claim_level ?? null,
            'evidence_class' => $assessment['evidence_class'] ?? null, 'research_status' => $assessment['research_status'] ?? null,
            'note' => 'Qualification was decided by the server when this signal was stored. This comparison does not change it.',
        ];
    }

    /** The evidence that is actually stored for the signal (the verified quote and its source), or nulls. @return array<string, mixed> */
    private function evidence(object $row): array
    {
        $a = $this->assessment($row);
        $fact = (array) (($a['provenance'] ?? [])['public_fact'] ?? []);
        $quote = isset($fact['quote']) && is_string($fact['quote']) && trim($fact['quote']) !== '' ? $fact['quote'] : null;
        $sources = json_decode((string) ($row->sources ?? '[]'), true) ?: [];
        $first = is_array($sources[0] ?? null) ? $sources[0] : [];
        $url = $fact['source_url'] ?? ($row->source_url ?? ($first['url'] ?? null));
        $title = $fact['source_title'] ?? ($row->source_title ?? ($first['title'] ?? null));

        $ev = (array) ($a['evidence'] ?? []);

        return [
            'available' => $quote !== null,
            'quote' => $quote,
            'source_title' => is_string($title) && $title !== '' ? $title : null,
            'source_url' => is_string($url) && $url !== '' ? $url : null,
            'quote_verified' => isset($ev['quote_verified']) ? (bool) $ev['quote_verified'] : null,
            'read_as' => $ev['read_as'] ?? null,
            'missing_evidence' => array_values(array_filter((array) ($a['missing_evidence'] ?? []), 'is_string')),
        ];
    }

    /** @return array<string, mixed> */
    private function assessment(object $row): array
    {
        $a = $row->assessment ?? null;

        return is_string($a) ? (json_decode($a, true) ?: []) : (array) $a;
    }

    /** The target account behind the organisation, in this ICP's own pack only. @return array<string, mixed>|null */
    private function account(int $tenantId, int $companyId, string $packKey): ?array
    {
        if ($companyId <= 0 || ! Schema::hasTable('g2g_target_accounts')) {
            return null;
        }
        $a = TargetAccount::where('sub_institute_id', $tenantId)->where('company_id', $companyId)->where('pack', $packKey)->orderByDesc('watch_priority')->first();

        return $a === null ? null : $a->toArray();
    }
}
