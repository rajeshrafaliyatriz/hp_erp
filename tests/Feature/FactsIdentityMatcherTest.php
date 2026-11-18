<?php

namespace Tests\Feature;

use App\Domain\Facts\EvidenceScorer;
use App\Domain\Facts\IdentityMatcher;
use Tests\TestCase;

/**
 * The golden tests of the identity matcher (slice 4), ported from Comp AI's identity.spec.ts, names.spec.ts and the namesMatch cases of socials.spec.ts
 * (MIT licence). Each test states the rule it pins.
 */
class FactsIdentityMatcherTest extends TestCase
{
    private const CLAIM = ['email' => 'pmarchetti@fernhill.com', 'company_name' => 'Fernhill', 'company_domain' => 'fernhill.com'];

    /** @return array{first_name: ?string, last_name: ?string, email: ?string, roles: list<array<string, mixed>>} */
    private function person(array $over = []): array
    {
        return $over + ['first_name' => 'Paula', 'last_name' => 'Marchetti', 'email' => null, 'roles' => [['organisation' => ['name' => 'Fernhill', 'domain' => 'fernhill.com']]]];
    }

    // ---- verdict (identity.spec.ts) ----

    public function test_the_address_the_person_published_themselves_settles_it_on_its_own(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(['email' => 'PMarchetti@Fernhill.com', 'roles' => []]), self::CLAIM);

        $this->assertTrue($verdict['email_matches'], 'compared without regard to case or surrounding spaces');
        $this->assertTrue($verdict['is_same_person']);
        $this->assertSame('high', $verdict['confidence']);
    }

    public function test_employer_and_name_together_are_accepted(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(), self::CLAIM);

        $this->assertTrue($verdict['employer_matches']);
        $this->assertTrue($verdict['name_matches']);
        $this->assertTrue($verdict['is_same_person']);
    }

    public function test_a_hyphenated_domain_that_is_not_the_employer_is_refused(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(['roles' => [['organisation' => ['name' => 'Acme', 'domain' => 'fern-hill.com']]]]), self::CLAIM);

        $this->assertFalse($verdict['employer_matches']);
        $this->assertFalse($verdict['is_same_person']);
    }

    public function test_when_only_one_check_passes_the_answer_is_a_suggestion_not_a_match(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(['first_name' => 'Antonio', 'last_name' => 'Fontana']), self::CLAIM);

        $this->assertTrue($verdict['employer_matches']);
        $this->assertFalse($verdict['name_matches']);
        $this->assertFalse($verdict['is_same_person']);
        $this->assertSame('medium', $verdict['confidence']);
    }

    public function test_a_name_alone_is_also_only_a_suggestion(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(['roles' => [['organisation' => ['name' => 'Brightwater', 'domain' => 'brightwater.example']]]]), self::CLAIM);

        $this->assertTrue($verdict['name_matches']);
        $this->assertFalse($verdict['employer_matches']);
        $this->assertFalse($verdict['is_same_person']);
        $this->assertSame('medium', $verdict['confidence']);
    }

    public function test_a_stranger_is_refused_outright(): void
    {
        $verdict = IdentityMatcher::verdict($this->person(['first_name' => 'Antonio', 'last_name' => 'Fontana', 'roles' => [['organisation' => ['name' => 'Brightwater', 'domain' => 'brightwater.example']]]]), self::CLAIM);

        $this->assertFalse($verdict['is_same_person']);
        $this->assertSame('low', $verdict['confidence']);
    }

    public function test_a_source_with_no_roles_and_no_address_matches_nothing_on_the_employer(): void
    {
        $verdict = IdentityMatcher::verdict(['first_name' => 'Paula', 'last_name' => 'Marchetti'], self::CLAIM);

        $this->assertFalse($verdict['employer_matches']);
        $this->assertFalse($verdict['email_matches']);
        $this->assertSame('medium', $verdict['confidence']);
    }

    public function test_the_evidence_a_verdict_stands_for_follows_the_original_skills_table(): void
    {
        $email = IdentityMatcher::evidenceFor(['email_matches' => true, 'employer_matches' => true, 'name_matches' => true], 'https://example.test/p', 'shows the address');
        $both = IdentityMatcher::evidenceFor(['email_matches' => false, 'employer_matches' => true, 'name_matches' => true]);
        $employer = IdentityMatcher::evidenceFor(['email_matches' => false, 'employer_matches' => true, 'name_matches' => false]);
        $nameOnly = IdentityMatcher::evidenceFor(['email_matches' => false, 'employer_matches' => false, 'name_matches' => true]);

        $this->assertSame([['kind' => 'profile.email-match', 'source_url' => 'https://example.test/p', 'quote' => 'shows the address']], $email, 'the address match is reported once, not once per check that also passed');
        $this->assertSame('linkedin.employer-and-name', $both[0]['kind']);
        $this->assertSame('employer-only', $employer[0]['kind']);
        $this->assertSame([], $nameOnly, 'a name on its own is nothing worth keeping');
        $this->assertSame('verified', EvidenceScorer::score($email)['band']);
        $this->assertSame('verified', EvidenceScorer::score($both)['band']);
        $this->assertNull(EvidenceScorer::score($employer)['band'], 'an employer match alone is below the floor: a colleague is not filed as the contact');
    }

    // ---- names.spec.ts ----

    public function test_an_employer_is_matched_to_the_shorter_name_the_crm_holds(): void
    {
        $this->assertTrue(IdentityMatcher::looksLikeSameCompany('Northwind Bank', 'Northwind', 'northwind.com'));
        $this->assertTrue(IdentityMatcher::looksLikeSameCompany('Fernhill', 'Fernhill', 'fernhill.com'));
        $this->assertTrue(IdentityMatcher::looksLikeSameCompany('Fernhill Group Ltd', '', 'fernhill.com'), 'the domain name part carries it when the CRM holds no company name');
    }

    public function test_an_unrelated_or_empty_employer_is_rejected(): void
    {
        $this->assertFalse(IdentityMatcher::looksLikeSameCompany('Brightwater Group', 'Fernhill', 'fernhill.com'));
        $this->assertFalse(IdentityMatcher::looksLikeSameCompany('', 'Fernhill', 'fernhill.com'));
        $this->assertFalse(IdentityMatcher::looksLikeSameCompany('Fernhill', '', ''), 'nothing to compare with is never a match');
    }

    public function test_the_initial_plus_surname_form_is_accepted(): void
    {
        $this->assertTrue(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Paula', 'last_name' => 'Marchetti'], 'pmarchetti'));
        $this->assertTrue(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Tomi', 'last_name' => 'Okonkwo'], 'tokonkwo'));
    }

    public function test_first_name_only_and_run_together_forms_are_accepted(): void
    {
        $this->assertTrue(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Nathan', 'last_name' => 'Owen'], 'nathan'));
        $this->assertTrue(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Jane', 'last_name' => 'Doe'], 'janedoe'));
        $this->assertTrue(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Jane', 'last_name' => 'Doe'], 'jane.doe'), 'a separator in the local part is ignored');
    }

    public function test_a_stranger_who_merely_turned_up_in_the_results_is_rejected(): void
    {
        $this->assertFalse(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Antonio', 'last_name' => 'Fontana'], 'pmarchetti'));
        $this->assertFalse(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Preeti', 'last_name' => 'Duarte'], 'tokonkwo'));
    }

    public function test_a_profile_with_no_name_at_all_never_matches(): void
    {
        $this->assertFalse(IdentityMatcher::nameMatchesLocalPart(['first_name' => null, 'last_name' => null], 'pmarchetti'));
        $this->assertFalse(IdentityMatcher::nameMatchesLocalPart(['first_name' => 'Paula', 'last_name' => 'Marchetti'], ''));
    }

    public function test_two_hyphenated_domains_are_kept_apart(): void
    {
        $this->assertFalse(IdentityMatcher::sameDomain('foo-bar.com', 'foobar.com'));
        $this->assertFalse(IdentityMatcher::sameDomain('the-hub.io', 'thehub.io'));
    }

    public function test_the_same_host_matches_however_it_is_written(): void
    {
        $this->assertTrue(IdentityMatcher::sameDomain('https://www.Fernhill.com/careers', 'fernhill.com'));
        $this->assertTrue(IdentityMatcher::sameDomain('fernhill.com.', 'FERNHILL.COM'));
    }

    public function test_different_companies_and_subdomains_are_kept_apart(): void
    {
        $this->assertFalse(IdentityMatcher::sameDomain('fernhill.com', 'brightwater.example'));
        $this->assertFalse(IdentityMatcher::sameDomain('mail.fernhill.com', 'fernhill.com'));
    }

    public function test_an_empty_or_missing_domain_is_never_the_same(): void
    {
        $this->assertFalse(IdentityMatcher::sameDomain(null, 'fernhill.com'));
        $this->assertFalse(IdentityMatcher::sameDomain('', 'fernhill.com'));
        $this->assertFalse(IdentityMatcher::sameDomain('fernhill.com', ''));
    }

    // ---- socials.spec.ts: namesMatch ----

    public function test_the_same_person_written_two_ways_is_accepted(): void
    {
        $this->assertTrue(IdentityMatcher::namesMatch('Lewis Carhart', 'Lewis Carhart'));
        $this->assertTrue(IdentityMatcher::namesMatch('Lewis J. Carhart', 'Lewis Carhart'));
        $this->assertTrue(IdentityMatcher::namesMatch('lewis carhart', 'Lewis Carhart'));
        $this->assertTrue(IdentityMatcher::namesMatch('Lewis James Carhart', 'Lewis Carhart'), 'the same first and last word');
    }

    public function test_a_near_miss_is_rejected(): void
    {
        $this->assertFalse(IdentityMatcher::namesMatch('Lewis Carter', 'Lewis Carhart'));
        $this->assertFalse(IdentityMatcher::namesMatch('Louis Carhart', 'Lewis Carhart'));
    }

    public function test_a_first_name_on_its_own_is_rejected(): void
    {
        $this->assertFalse(IdentityMatcher::namesMatch('Lewis', 'Lewis Carhart'));
        $this->assertFalse(IdentityMatcher::namesMatch(null, 'Lewis Carhart'));
        $this->assertFalse(IdentityMatcher::namesMatch('Lewis Carhart', ''), 'nothing on one side is never a match');
    }

    // ---- the small helpers ----

    public function test_a_full_name_is_split_into_first_and_the_rest(): void
    {
        $this->assertSame(['first_name' => 'Paula', 'last_name' => 'Marchetti'], IdentityMatcher::splitName('  Paula   Marchetti '));
        $this->assertSame(['first_name' => 'Paula', 'last_name' => 'de la Cruz'], IdentityMatcher::splitName('Paula de la Cruz'));
        $this->assertSame(['first_name' => 'Cher', 'last_name' => null], IdentityMatcher::splitName('Cher'));
        $this->assertNull(IdentityMatcher::splitName('   '));
    }

    public function test_the_domain_of_an_email_is_its_last_part_lower_case(): void
    {
        $this->assertSame('fernhill.com', IdentityMatcher::domainOf('Paula@Fernhill.com'));
        $this->assertNull(IdentityMatcher::domainOf('not-an-email'));
        $this->assertNull(IdentityMatcher::domainOf('@fernhill.com'), 'nothing before the @ is not an address');
    }
}
