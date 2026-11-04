<?php

namespace Tests\Support;

use App\Domain\Crm\Sales\OpportunityGtmSyncBridge;
use Illuminate\Support\Facades\DB;

/**
 * A GTM sync bridge that fails, for the tests of defect D17. Bound in the container in place of the real one, it throws from both directions and remembers
 * that it was asked, so a test can prove the sync WAS attempted (and failed) and the save still answered.
 *
 * $leaveTransactionOpen makes it start a database transaction before it throws and never close it: the state a careless sync could leave behind.
 */
class ThrowingGtmBridge extends OpportunityGtmSyncBridge
{
    /** @var list<string> "direction:tenant:id" of every attempt */
    public static array $calls = [];

    public static bool $leaveTransactionOpen = false;

    public static function syncOpportunityToGtm(int $tenantId, int $opportunityId, ?int $actorId = null): ?int
    {
        return self::fail("forward:{$tenantId}:{$opportunityId}");
    }

    public static function syncGtmDealToOpportunity(int $tenantId, int $dealId, ?int $actorId = null): ?int
    {
        return self::fail("reverse:{$tenantId}:{$dealId}");
    }

    private static function fail(string $call): never
    {
        self::$calls[] = $call;
        if (self::$leaveTransactionOpen) {
            DB::beginTransaction();
        }

        throw new \RuntimeException('GTM sync is down (a test made it fail)');
    }
}
