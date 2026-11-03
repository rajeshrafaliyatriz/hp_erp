<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgIdentity;
use App\Domain\OrgAccounts\OrgLinkConflict;
use App\Domain\OrgAccounts\OrgLinkNotFound;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOrgAccountsWorld;
use Tests\TestCase;

/** Confirming and rejecting a suggested pair (slice 3, D2 and acceptance check 5). */
class OrgAccountsLinkerTest extends TestCase
{
    use BuildsOrgAccountsWorld;

    private const T = 1;

    private const OTHER = 2;

    private const USER = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrgAccountsWorld();
    }

    private function linker(): OrgLinker
    {
        return new OrgLinker;
    }

    /** @param array<string, mixed> $o */
    private function identity(array $o): OrgAccount
    {
        return OrgAccount::query()->create($o + ['sub_institute_id' => self::T, 'name' => 'Held', 'normalized_name' => 'held', 'lifecycle' => 'discovered']);
    }

    private function table(): array
    {
        return DB::table('g2g_org_accounts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    // ---- confirm: creating, attaching, merging ----

    public function test_confirm_puts_two_unlinked_rows_in_one_new_identity_with_basis_manual(): void
    {
        $gtm = $this->gtm('Alpha Academy Gandhinagar', self::T, ['domain' => 'alpha.example.org']);
        $company = $this->company('Alpha Academy');

        $out = $this->linker()->confirm(self::T, self::USER, 'g2g_company', $company, 'gtm_account', $gtm);

        $this->assertSame(1, OrgAccount::query()->count());
        $this->assertSame($gtm, $out['gtm_account_id']);
        $this->assertSame($company, $out['g2g_company_id']);
        $this->assertNull($out['crm_organization_id']);
        $this->assertSame(['manual', 'manual', null], [$out['gtm_link_basis'], $out['company_link_basis'], $out['crm_link_basis']]);
        $this->assertSame('Alpha Academy Gandhinagar', $out['name'], 'the GTM name before the company name');
        $this->assertSame('target', $out['lifecycle']);
        $this->assertSame('alpha.example.org', $out['domain']);
        $this->assertSame(self::USER, (int) OrgAccount::query()->first()->created_by);
    }

    public function test_confirm_attaches_the_row_to_the_identity_the_other_one_already_has(): void
    {
        $company = $this->company('Beta Learning');
        $held = $this->identity(['name' => 'Beta (hand named)', 'g2g_company_id' => $company, 'company_link_basis' => 'exact_name']);
        $crm = $this->crm('Beta Learning Centre', self::T, ['website' => 'https://beta.example.org']);

        $out = $this->linker()->confirm(self::T, self::USER, 'crm_organization', $crm, 'g2g_company', $company);

        $this->assertSame(1, OrgAccount::query()->count());
        $this->assertSame($held->id, $out['id']);
        $this->assertSame($crm, $out['crm_organization_id']);
        $this->assertSame('manual', $out['crm_link_basis']);
        $this->assertSame('exact_name', $out['company_link_basis'], 'the existing record keeps its basis');
        $this->assertSame('Beta (hand named)', $out['name'], 'a name that exists is kept');
        $this->assertSame('engaged', $out['lifecycle'], 'a CRM account raises discovered to engaged');
        $this->assertSame('beta.example.org', $out['domain']);
    }

    public function test_confirm_never_lowers_or_moves_an_archived_or_customer_lifecycle(): void
    {
        $company = $this->company('Gamma');
        $this->identity(['lifecycle' => 'customer', 'g2g_company_id' => $company]);
        $crm = $this->crm('Gamma');

        $this->assertSame('customer', $this->linker()->confirm(self::T, null, 'crm_organization', $crm, 'g2g_company', $company)['lifecycle']);
        $this->assertSame('archived', OrgIdentity::raise('archived', 'engaged'));
        $this->assertSame('engaged', OrgIdentity::raise('discovered', 'engaged'));
        $this->assertSame('engaged', OrgIdentity::raise('engaged', 'target'));
    }

    public function test_confirm_merges_two_identities_into_the_older_one(): void
    {
        $company = $this->company('Delta Group');
        $gtm = $this->gtm('Delta Group Ltd');
        $crm = $this->crm('Delta Group Inc');
        $older = $this->identity(['name' => 'Delta older', 'gtm_account_id' => $gtm, 'gtm_link_basis' => 'exact_name', 'lifecycle' => 'target']);
        $newer = $this->identity(['name' => 'Delta newer', 'g2g_company_id' => $company, 'crm_organization_id' => $crm, 'company_link_basis' => 'exact_domain',
            'crm_link_basis' => 'existing_link', 'lifecycle' => 'engaged', 'owner_user_id' => 9, 'domain' => 'delta.example.org']);

        // Either order of the two ends gives the same result.
        $out = $this->linker()->confirm(self::T, self::USER, 'g2g_company', $company, 'gtm_account', $gtm);

        $this->assertSame($older->id, $out['id'], 'the survivor keeps the older id');
        $this->assertSame([$gtm, $company, $crm], [$out['gtm_account_id'], $out['g2g_company_id'], $out['crm_organization_id']]);
        $this->assertSame('Delta older', $out['name']);
        $this->assertSame('engaged', $out['lifecycle'], 'the higher of the two stages');
        $this->assertSame(9, $out['owner_user_id'], 'an empty owner takes the other\'s');
        $this->assertSame('delta.example.org', $out['domain']);
        $this->assertSame(['exact_name', 'manual', 'manual'], [$out['gtm_link_basis'], $out['company_link_basis'], $out['crm_link_basis']]);

        $loser = OrgAccount::withTrashed()->find($newer->id);
        $this->assertNotNull($loser->deleted_at, 'the other identity is soft-deleted');
        $this->assertSame([null, null, null], [$loser->crm_organization_id, $loser->gtm_account_id, $loser->g2g_company_id], 'with its pointers freed first');
        $this->assertSame([null, null, null], [$loser->crm_link_basis, $loser->gtm_link_basis, $loser->company_link_basis]);
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_confirm_merging_is_the_same_whichever_end_is_the_older_identity(): void
    {
        $gtm = $this->gtm('Epsilon');
        $company = $this->company('Epsilon');
        $older = $this->identity(['g2g_company_id' => $company]);
        $this->identity(['gtm_account_id' => $gtm]);

        $out = $this->linker()->confirm(self::T, null, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->assertSame($older->id, $out['id']);
        $this->assertSame([$gtm, $company], [$out['gtm_account_id'], $out['g2g_company_id']]);
    }

    public function test_confirming_a_pair_already_in_one_identity_changes_nothing(): void
    {
        $gtm = $this->gtm('Zeta');
        $company = $this->company('Zeta');
        $held = $this->identity(['gtm_account_id' => $gtm, 'g2g_company_id' => $company, 'gtm_link_basis' => 'exact_name', 'company_link_basis' => 'exact_name']);
        $before = $this->table();

        $out = $this->linker()->confirm(self::T, self::USER, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->assertSame($held->id, $out['id']);
        $this->assertSame($before, $this->table());
    }

    // ---- confirm: refusals leave nothing behind ----

    public function test_two_identities_that_both_hold_a_row_of_one_source_cannot_be_merged(): void
    {
        $g1 = $this->gtm('Eta One');
        $g2 = $this->gtm('Eta Two');
        $c = $this->company('Eta');
        $this->identity(['gtm_account_id' => $g1, 'crm_organization_id' => $this->crm('Eta CRM')]);
        $this->identity(['gtm_account_id' => $g2, 'g2g_company_id' => $c]);
        $before = $this->table();

        try {
            $this->linker()->confirm(self::T, self::USER, 'gtm_account', $g1, 'g2g_company', $c);
            $this->fail('the merge must be refused');
        } catch (OrgLinkConflict $e) {
            $this->assertSame(OrgLinkConflict::SAME_SOURCE, $e->reason);
        }

        $this->assertSame($before, $this->table(), 'nothing changed: neither identity, nor a deletion');
    }

    public function test_a_row_cannot_join_an_identity_that_already_has_another_row_of_its_source(): void
    {
        $g1 = $this->gtm('Theta One');
        $g2 = $this->gtm('Theta Two');
        $c = $this->company('Theta');
        $this->identity(['gtm_account_id' => $g1, 'g2g_company_id' => $c]);
        $before = $this->table();

        $this->expectException(OrgLinkConflict::class);
        try {
            $this->linker()->confirm(self::T, null, 'gtm_account', $g2, 'g2g_company', $c);
        } finally {
            $this->assertSame($before, $this->table());
        }
    }

    public function test_a_row_cannot_be_linked_to_itself(): void
    {
        $gtm = $this->gtm('Iota');

        try {
            $this->linker()->confirm(self::T, null, 'gtm_account', $gtm, 'gtm_account', $gtm);
            $this->fail('refused');
        } catch (OrgLinkConflict $e) {
            $this->assertSame(OrgLinkConflict::SAME_ROW, $e->reason);
        }
        $this->assertSame(0, OrgAccount::query()->count());
    }

    public function test_a_row_that_is_missing_deleted_or_another_organisations_is_not_found_and_nothing_is_written(): void
    {
        $mine = $this->company('Kappa');
        $deleted = $this->gtm('Kappa', self::T, ['deleted_at' => now()]);
        $theirs = $this->gtm('Kappa', self::OTHER);

        foreach ([[$mine, 'gtm_account', 99999], [$mine, 'gtm_account', $deleted], [$mine, 'gtm_account', $theirs]] as [$id, $type, $other]) {
            try {
                $this->linker()->confirm(self::T, null, 'g2g_company', $id, $type, $other);
                $this->fail('not found expected');
            } catch (OrgLinkNotFound) {
                $this->assertTrue(true);
            }
            try {
                $this->linker()->reject(self::T, null, 'g2g_company', $id, $type, $other);
                $this->fail('not found expected');
            } catch (OrgLinkNotFound) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, OrgAccount::query()->count());
        $this->assertSame(0, DB::table('g2g_org_link_rejections')->count());
    }

    public function test_another_organisations_confirm_cannot_reach_this_ones_rows(): void
    {
        $gtm = $this->gtm('Lambda');
        $company = $this->company('Lambda');

        $this->expectException(OrgLinkNotFound::class);
        $this->linker()->confirm(self::OTHER, null, 'gtm_account', $gtm, 'g2g_company', $company);
    }

    public function test_an_unknown_source_type_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->linker()->confirm(self::T, null, 'tbluser', 1, 'g2g_company', 1);
    }

    // ---- reject ----

    public function test_reject_is_recorded_once_whichever_order_it_is_said_in_and_changes_no_identity(): void
    {
        $gtm = $this->gtm('Mu Academy');
        $company = $this->company('Mu Academy');
        $this->identity(['gtm_account_id' => $gtm]);
        $before = $this->table();

        $this->linker()->reject(self::T, self::USER, 'gtm_account', $gtm, 'g2g_company', $company);
        $this->linker()->reject(self::T, self::USER, 'gtm_account', $gtm, 'g2g_company', $company);
        $this->linker()->reject(self::T, self::USER, 'g2g_company', $company, 'gtm_account', $gtm);

        $this->assertSame(1, DB::table('g2g_org_link_rejections')->count());
        $row = DB::table('g2g_org_link_rejections')->first();
        $this->assertSame(['g2g_company', $company, 'gtm_account', $gtm, self::T, self::USER], [$row->left_type, (int) $row->left_id, $row->right_type, (int) $row->right_id, (int) $row->sub_institute_id, (int) $row->decided_by],
            'stored in one order: by source type, then id');
        $this->assertSame($before, $this->table(), 'an identity is never changed by a rejection');
    }

    public function test_a_rejected_pair_is_never_offered_again_in_either_order(): void
    {
        $gtm = $this->gtm('Nu Academy');
        $company = $this->company('Nu Academy');
        $this->assertCount(1, (new OrgResolver)->suggestions(self::T));

        $this->linker()->reject(self::T, null, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->assertSame([], (new OrgResolver)->suggestions(self::T));
        $this->assertSame(0, (new OrgResolver)->suggestionCount(self::T));
    }

    public function test_confirming_a_pair_that_was_rejected_clears_the_rejection(): void
    {
        $gtm = $this->gtm('Xi Academy');
        $company = $this->company('Xi Academy');
        $this->linker()->reject(self::T, null, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->linker()->confirm(self::T, null, 'g2g_company', $company, 'gtm_account', $gtm);

        $this->assertSame(0, DB::table('g2g_org_link_rejections')->count());
        $this->assertSame(1, OrgAccount::query()->count());
    }

    public function test_a_confirmed_pair_is_no_longer_offered(): void
    {
        $gtm = $this->gtm('Omicron Academy');
        $company = $this->company('Omicron Academy');
        $this->assertCount(1, (new OrgResolver)->suggestions(self::T));

        $this->linker()->confirm(self::T, null, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->assertSame([], (new OrgResolver)->suggestions(self::T));
    }

    public function test_the_two_rejection_orders_in_the_table_are_both_honoured(): void
    {
        $gtm = $this->gtm('Pi Academy');
        $company = $this->company('Pi Academy');
        // A row written by hand in the other order must still count.
        DB::table('g2g_org_link_rejections')->insert(['sub_institute_id' => self::T, 'left_type' => 'gtm_account', 'left_id' => $gtm, 'right_type' => 'g2g_company', 'right_id' => $company]);

        $this->assertSame([], (new OrgResolver)->suggestions(self::T));
        $this->linker()->reject(self::T, null, 'g2g_company', $company, 'gtm_account', $gtm);
        $this->assertSame(1, DB::table('g2g_org_link_rejections')->count(), 'not recorded a second time');
    }
}
