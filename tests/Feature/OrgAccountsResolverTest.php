<?php

namespace Tests\Feature;

use App\Domain\OrgAccounts\OrgAccount;
use App\Domain\OrgAccounts\OrgBackfill;
use App\Domain\OrgAccounts\OrgLinkConflict;
use App\Domain\OrgAccounts\OrgLinker;
use App\Domain\OrgAccounts\OrgNormalizer;
use App\Domain\OrgAccounts\OrgResolver;
use Tests\Support\BuildsOrgAccountsWorld;
use Tests\TestCase;

/** The suggestions a person reviews (slice 3, D2): pairs of rows alike enough to be the same organisation, never auto-linked. */
class OrgAccountsResolverTest extends TestCase
{
    use BuildsOrgAccountsWorld;

    private const T = 1;

    private const OTHER = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrgAccountsWorld();
    }

    private function resolver(): OrgResolver
    {
        return new OrgResolver;
    }

    /** @return list<string> */
    private function keys(int $tenant = self::T): array
    {
        return array_column($this->resolver()->suggestions($tenant), 'key');
    }

    public function test_pairs_are_offered_from_eighty_percent_of_their_words_and_carry_the_contracted_shape(): void
    {
        $company = $this->company('Delhi Public Academy Noida');
        $gtm = $this->gtm('Delhi Public Academy Noida Extension');   // 4 of 5 words: 0.8
        $this->gtm('Delhi Public Academy', self::T, ['location' => 'x']);   // 3 of 4 words against the company: 0.75, not offered
        $this->crm('Totally Different');

        $suggestions = $this->resolver()->suggestions(self::T);

        $this->assertCount(1, $suggestions);
        $this->assertSame([
            'key' => "g2g_company:{$company}|gtm_account:{$gtm}",
            'left' => ['type' => 'g2g_company', 'id' => $company, 'name' => 'Delhi Public Academy Noida', 'place' => null],
            'right' => ['type' => 'gtm_account', 'id' => $gtm, 'name' => 'Delhi Public Academy Noida Extension', 'place' => null],
            'basis' => 'name_overlap',
            'score' => 0.8,
            'place_conflict' => false,
            'confirmable' => true,
        ], $suggestions[0]);
    }

    public function test_the_pair_is_ordered_by_source_type_then_id_whichever_row_was_found_first(): void
    {
        $gtm = $this->gtm('Alpha Beta');
        $crm = $this->crm('Alpha Beta');
        $company = $this->company('Alpha Beta');

        $keys = $this->keys();

        sort($keys);
        $this->assertSame([
            "crm_organization:{$crm}|g2g_company:{$company}",
            "crm_organization:{$crm}|gtm_account:{$gtm}",
            "g2g_company:{$company}|gtm_account:{$gtm}",
        ], $keys);
    }

    public function test_best_score_first_then_the_limit_cuts_the_list(): void
    {
        $this->company('One Two Three Four Five');
        $this->crm('One Two Three Four Five');             // 1.0
        $this->gtm('One Two Three Four Five Six');          // 5/6 against both: 0.8333
        $suggestions = $this->resolver()->suggestions(self::T);

        $this->assertSame([1.0, 0.8333, 0.8333], array_column($suggestions, 'score'));
        $this->assertCount(2, $this->resolver()->suggestions(self::T, 2));
        $this->assertSame(1.0, $this->resolver()->suggestions(self::T, 1)[0]['score']);
        $this->assertSame([], $this->resolver()->suggestions(self::T, 0));
        $this->assertSame(3, $this->resolver()->suggestionCount(self::T));
    }

    public function test_only_this_organisations_rows_are_offered(): void
    {
        $this->gtm('Rho Academy', self::OTHER);
        $this->company('Rho Academy', self::OTHER);
        $this->gtm('Sigma Academy', self::T);
        $this->company('Sigma Academy', self::OTHER);

        $this->assertSame([], $this->keys(self::T), 'an organisation\'s row is never paired with another\'s');
        $this->assertCount(1, $this->keys(self::OTHER));
        $this->assertSame(1, $this->resolver()->suggestionCount(self::OTHER));
    }

    public function test_two_rows_of_one_source_are_offered_only_for_signals_companies(): void
    {
        $c1 = $this->company('Tau Academy');
        $c2 = $this->company('Tau Academy Pvt Ltd');
        $this->crm('Upsilon Academy');
        $this->crm('Upsilon Academy Pvt Ltd');
        $this->gtm('Phi Academy');
        $this->gtm('Phi Academy', self::T);

        $this->assertSame(["g2g_company:{$c1}|g2g_company:{$c2}"], $this->keys());
    }

    public function test_rows_with_no_usable_name_are_never_offered(): void
    {
        $this->crm('The School');
        $this->gtm('School Pvt Ltd');
        $this->company('');

        $this->assertSame([], $this->keys());
    }

    public function test_rows_already_in_one_identity_are_not_offered(): void
    {
        $gtm = $this->gtm('Chi Academy');
        $company = $this->company('Chi Academy');
        $other = $this->company('Chi Academy Ltd');
        OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'Chi', 'normalized_name' => 'academy chi', 'lifecycle' => 'target', 'gtm_account_id' => $gtm, 'g2g_company_id' => $company]);

        // The held pair is gone. The third row is a second Signals company: against the GTM account it would put two companies into the one identity
        // (OrgLinker refuses that with a 409), so that pair is NOT offered; against the company it is a pair of one source, which can only be turned down.
        $this->assertSame(["g2g_company:{$company}|g2g_company:{$other}"], $this->sorted($this->keys()));
        $this->assertFalse($this->resolver()->suggestions(self::T)[0]['confirmable']);
    }

    /** @param list<string> $keys @return list<string> */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    // ---- the four cases the owner must be able to see and settle ----

    public function test_an_ambiguous_identical_name_pair_is_offered_with_score_one_after_the_backfill(): void
    {
        $c1 = $this->company('Psi International Academy');
        $c2 = $this->company('PSI International Academy Pvt Ltd');
        $gtm = $this->gtm('Psi International Academy');

        $r = (new OrgBackfill)->run(self::T, true);

        $this->assertSame(2, $r['ambiguous']);
        $suggestions = $this->resolver()->suggestions(self::T);
        $this->assertSame([1.0, 1.0, 1.0], array_column($suggestions, 'score'));
        $this->assertSame(
            $this->sorted(["g2g_company:{$c1}|g2g_company:{$c2}", "g2g_company:{$c1}|gtm_account:{$gtm}", "g2g_company:{$c2}|gtm_account:{$gtm}"]),
            $this->sorted(array_column($suggestions, 'key'))
        );
        $this->assertSame(['name_overlap'], array_values(array_unique(array_column($suggestions, 'basis'))));
    }

    public function test_a_same_name_different_place_pair_is_offered_with_score_one(): void
    {
        $gtm = $this->gtm('Omega Vidyalaya', self::T, ['location' => 'Surat, Gujarat']);
        $company = $this->company('Omega Vidyalaya', self::T, ['location' => 'Pune', 'state' => 'Maharashtra']);
        (new OrgBackfill)->run(self::T, true);

        $suggestions = $this->resolver()->suggestions(self::T);

        $this->assertCount(1, $suggestions);
        $this->assertSame("g2g_company:{$company}|gtm_account:{$gtm}", $suggestions[0]['key']);
        $this->assertSame(1.0, $suggestions[0]['score']);
    }

    public function test_once_confirmed_through_the_linker_the_pair_is_gone(): void
    {
        $gtm = $this->gtm('Omega Vidyalaya', self::T, ['location' => 'Surat, Gujarat']);
        $company = $this->company('Omega Vidyalaya', self::T, ['location' => 'Pune', 'state' => 'Maharashtra']);
        (new OrgBackfill)->run(self::T, true);
        $this->assertCount(1, $this->keys());

        (new OrgLinker)->confirm(self::T, 3, 'gtm_account', $gtm, 'g2g_company', $company);

        $this->assertSame([], $this->keys());
        $this->assertSame(0, $this->resolver()->suggestionCount(self::T));
    }

    public function test_once_rejected_the_pair_is_gone_and_stays_gone_after_another_backfill(): void
    {
        $gtm = $this->gtm('Omega Vidyalaya', self::T, ['location' => 'Surat, Gujarat']);
        $company = $this->company('Omega Vidyalaya', self::T, ['location' => 'Pune', 'state' => 'Maharashtra']);
        (new OrgBackfill)->run(self::T, true);

        (new OrgLinker)->reject(self::T, 3, 'g2g_company', $company, 'gtm_account', $gtm);
        (new OrgBackfill)->run(self::T, true);

        $this->assertSame([], $this->keys());
    }

    public function test_an_exact_pair_the_backfill_has_not_yet_linked_is_offered_and_is_gone_once_it_has(): void
    {
        $gtm = $this->gtm('Alpha Prime Academy');
        $company = $this->company('Alpha Prime Academy');

        $this->assertSame(["g2g_company:{$company}|gtm_account:{$gtm}"], $this->keys(), 'only same-identity and rejected pairs are left out');
        $dry = (new OrgBackfill)->run(self::T, false);
        $this->assertSame(0, $dry['suggestions'], 'the dry run reports what is left once the backfill has linked it');

        (new OrgBackfill)->run(self::T, true);

        $this->assertSame([], $this->keys());
    }

    // ---- what the walkthrough found: the place conflict (D2) and the pairs that can never be confirmed (D3) ----

    public function test_a_same_name_pair_in_two_different_places_says_so_and_names_both_places(): void
    {
        $gtm = $this->gtm('Omega Vidyalaya', self::T, ['location' => 'Surat, Gujarat']);
        $company = $this->company('Omega Vidyalaya', self::T, ['location' => 'Mumbai', 'state' => 'Maharashtra']);
        (new OrgBackfill)->run(self::T, true);

        $pair = $this->resolver()->suggestions(self::T)[0];

        $this->assertTrue($pair['place_conflict']);
        $this->assertSame(['g2g_company', $company, 'Mumbai, Maharashtra'], [$pair['left']['type'], $pair['left']['id'], $pair['left']['place']]);
        $this->assertSame(['gtm_account', $gtm, 'Surat, Gujarat'], [$pair['right']['type'], $pair['right']['id'], $pair['right']['place']]);
    }

    public function test_an_ordinary_pair_is_not_marked_as_a_place_conflict(): void
    {
        $this->gtm('Delta Public Academy Noida', self::T, ['location' => 'Noida']);
        $this->company('Delta Public Academy Noida Extension');                           // no place at all: contradicts nothing
        $this->crm('Delta Public Academy Noida Branch', self::T, ['billing_city' => 'Noida']);   // the same place: not a contradiction either

        $pairs = $this->resolver()->suggestions(self::T);

        $this->assertNotEmpty($pairs);
        $this->assertSame([false], array_values(array_unique(array_column($pairs, 'place_conflict'))));
        $places = [];
        foreach ($pairs as $pair) {
            $places[] = $pair['left']['place'];
            $places[] = $pair['right']['place'];
        }
        $this->assertContains(null, $places, 'a row with no place says null, not an empty string');
        $this->assertNotContains('', $places);
    }

    public function test_a_state_alone_is_no_place_so_the_same_state_is_not_a_conflict(): void
    {
        $this->gtm('Kappa Academy', self::T, ['location' => 'Gujarat']);
        $this->company('Kappa Academy', self::T, ['location' => 'Gujarat']);

        $this->assertFalse($this->resolver()->suggestions(self::T)[0]['place_conflict'], 'a state is no evidence of a place');
    }

    public function test_after_a_confirm_the_pairs_that_would_put_two_rows_of_one_source_into_the_identity_are_no_longer_offered(): void
    {
        // The walkthrough's Charlie: two Signals companies and one GTM account, all with the same name once "school" is dropped.
        $a = $this->company('Charlie School');
        $b = $this->company('Charlie');
        $gtm = $this->gtm('Charlie School');
        (new OrgBackfill)->run(self::T, true);
        $this->assertSame(
            $this->sorted(["g2g_company:{$a}|g2g_company:{$b}", "g2g_company:{$a}|gtm_account:{$gtm}", "g2g_company:{$b}|gtm_account:{$gtm}"]),
            $this->sorted($this->keys()),
            'before the decision all three pairs are questions'
        );

        (new OrgLinker)->confirm(self::T, 3, 'g2g_company', $a, 'gtm_account', $gtm);

        // (b, the GTM account) would give the identity two Signals companies: the linker answers it with a 409, so it is no longer offered.
        $this->assertSame(["g2g_company:{$a}|g2g_company:{$b}"], $this->keys());
        $this->expectException(OrgLinkConflict::class);
        (new OrgLinker)->confirm(self::T, 3, 'g2g_company', $b, 'gtm_account', $gtm);
    }

    public function test_two_rows_of_one_source_are_offered_but_marked_as_never_confirmable_and_every_other_pair_is_confirmable(): void
    {
        $c1 = $this->company('Tau Academy');
        $c2 = $this->company('Tau Academy Pvt Ltd');
        $gtm = $this->gtm('Tau Academy');

        $byKey = [];
        foreach ($this->resolver()->suggestions(self::T) as $pair) {
            $byKey[$pair['key']] = $pair['confirmable'];
        }

        $this->assertSame(false, $byKey["g2g_company:{$c1}|g2g_company:{$c2}"]);
        $this->assertSame(true, $byKey["g2g_company:{$c1}|gtm_account:{$gtm}"]);
        $this->assertSame(true, $byKey["g2g_company:{$c2}|gtm_account:{$gtm}"]);
    }

    public function test_a_pointer_whose_row_is_gone_still_blocks_the_pair_the_way_the_linker_does(): void
    {
        $gtm = $this->gtm('Upsilon Academy');
        $other = $this->company('Upsilon Academy');
        // The identity points at Signals company 9999, which does not exist any more: its slot is still taken, and OrgLinker::attach refuses a second company.
        OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'Upsilon', 'normalized_name' => 'academy upsilon', 'lifecycle' => 'target', 'gtm_account_id' => $gtm, 'g2g_company_id' => 9999]);

        $this->assertSame([], $this->keys());
        $this->expectException(OrgLinkConflict::class);
        (new OrgLinker)->confirm(self::T, 3, 'g2g_company', $other, 'gtm_account', $gtm);
    }

    public function test_two_identities_are_offered_as_a_pair_only_when_a_merge_would_be_accepted(): void
    {
        $crmA = $this->crm('Phi Academy');
        $companyA = $this->company('Phi Public Academy Noida');
        $gtmB = $this->gtm('Phi Academy');
        $companyB = $this->company('Phi Public Academy Noida Branch');
        // Identity 1 = CRM + Signals company A; identity 2 = GTM + Signals company B. Both hold a company, so merging them would hold two.
        OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'One', 'normalized_name' => 'one', 'lifecycle' => 'engaged', 'crm_organization_id' => $crmA, 'g2g_company_id' => $companyA]);
        OrgAccount::query()->create(['sub_institute_id' => self::T, 'name' => 'Two', 'normalized_name' => 'two', 'lifecycle' => 'target', 'gtm_account_id' => $gtmB, 'g2g_company_id' => $companyB]);

        $keys = $this->keys();
        $this->assertNotContains("crm_organization:{$crmA}|gtm_account:{$gtmB}", $keys, 'merging identity 1 and identity 2 is refused (a 409)');
        $this->assertContains("g2g_company:{$companyA}|g2g_company:{$companyB}", $keys, 'the two companies stay as a pair that can only be turned down');

        // Free the company slot of identity 2: now the merge is acceptable, and the pair is a question again.
        OrgAccount::query()->where('gtm_account_id', $gtmB)->update(['g2g_company_id' => null]);
        $this->assertContains("crm_organization:{$crmA}|gtm_account:{$gtmB}", $this->keys());
        (new OrgLinker)->confirm(self::T, 3, 'crm_organization', $crmA, 'gtm_account', $gtmB);
        $this->assertSame(1, OrgAccount::query()->where('gtm_account_id', $gtmB)->count());
    }

    // ---- the search itself ----

    public function test_the_prefix_filter_finds_exactly_the_pairs_a_full_comparison_finds(): void
    {
        $vocabulary = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa', 'lambda', 'mu', 'nu', 'xi'];
        mt_srand(11);
        $names = [];
        for ($i = 0; $i < 150; $i++) {
            $words = [];
            for ($w = mt_rand(1, 7); $w > 0; $w--) {
                $words[] = $vocabulary[mt_rand(0, count($vocabulary) - 1)];
            }
            $names[$this->company(implode(' ', $words))] = OrgNormalizer::tokens(implode(' ', $words));
        }

        $expected = [];
        $ids = array_keys($names);
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                if (OrgNormalizer::overlap($names[$a], $names[$b]) >= OrgResolver::OVERLAP_MIN) {
                    $expected[] = "g2g_company:{$a}|g2g_company:{$b}";
                }
            }
        }
        sort($expected);

        $this->assertGreaterThan(15, count($expected), 'the sample must contain real near-matches, or this proves nothing');
        $this->assertSame($expected, $this->sorted(array_column($this->resolver()->suggestions(self::T, 100000), 'key')));
    }
}
