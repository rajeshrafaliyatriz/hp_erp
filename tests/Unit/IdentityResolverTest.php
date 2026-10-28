<?php

namespace Tests\Unit;

use App\Domain\Signals\Scholar\IdentityResolver;
use Tests\TestCase;

/** A candidate is attached to a target school only when name AND place (or website) agree. Pure. */
class IdentityResolverTest extends TestCase
{
    private function accounts(): array
    {
        return [
            ['id' => 1, 'name' => 'Tapovan International School', 'city' => 'Mahesana', 'state' => 'Gujarat', 'website' => null, 'crm_ref' => 'LEA177'],
            ['id' => 2, 'name' => 'Ryan International School', 'city' => 'Rajkot', 'state' => 'Gujarat', 'website' => 'www.ryangroup.org', 'crm_ref' => 'LEA82'],
            ['id' => 3, 'name' => 'Nirma Vidya Vihar', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'website' => 'www.nirmavidyavihar.co.in', 'crm_ref' => 'LEA35'],
            ['id' => 4, 'name' => 'Vibrant International Academy', 'city' => 'Surat', 'state' => 'Gujarat', 'website' => null, 'crm_ref' => 'LEA203'],
            ['id' => 5, 'name' => 'Vibrant International Academy', 'city' => 'Anand', 'state' => 'Gujarat', 'website' => null, 'crm_ref' => 'LEA204'],
            ['id' => 6, 'name' => 'Podar World School Maneja', 'city' => 'Vadodara', 'state' => 'Gujarat', 'website' => 'www.podar.org', 'crm_ref' => 'LEA59'],
        ];
    }

    private function r(string $name, ?string $location = null, ?string $website = null): array
    {
        return IdentityResolver::resolve(['name' => $name, 'location' => $location, 'website' => $website], $this->accounts());
    }

    public function test_tapovan_vidya_vihar_in_ahmedabad_is_not_tapovan_international_school_in_mahesana(): void
    {
        $r = $this->r('Tapovan Vidya Vihar', 'Vasana, Ahmedabad, Gujarat');
        $this->assertSame('unrelated', $r['status']);
        $this->assertNull($r['account_id']);

        // Even with no place stated, a name that only shares one word is not enough.
        $this->assertSame('unrelated', $this->r('Tapovan Vidya Vihar')['status']);
    }

    public function test_the_same_name_and_the_same_place_is_an_exact_match(): void
    {
        $r = $this->r('Tapovan International School', 'Mahesana, Gujarat');
        $this->assertSame('target_exact', $r['status']);
        $this->assertSame(1, $r['account_id']);
        $this->assertSame('LEA177', $r['crm_ref']);
    }

    public function test_the_same_name_in_another_city_is_another_school(): void
    {
        $this->assertSame('unrelated', $this->r('Ryan International School', 'Mumbai')['status'], 'another branch of the group is another school');
        $this->assertSame('target_exact', $this->r('Ryan International School', 'Rajkot')['status']);
    }

    public function test_the_same_name_with_no_place_is_possible_and_needs_review(): void
    {
        $r = $this->r('Ryan International School');
        $this->assertSame('target_possible', $r['status']);
        $this->assertSame(2, $r['account_id']);
        $this->assertStringContainsString('confirm', $r['reasons'][0]);
    }

    public function test_the_same_website_is_an_exact_match_whatever_the_wording(): void
    {
        $this->assertSame('target_exact', $this->r('RIS Rajkot Campus', null, 'https://www.ryangroup.org/')['status']);
    }

    public function test_the_place_picks_between_two_accounts_with_one_name(): void
    {
        $this->assertSame(4, $this->r('Vibrant International Academy', 'Surat')['account_id']);
        $this->assertSame(5, $this->r('Vibrant International Academy', 'Anand, Gujarat')['account_id']);
        $this->assertSame('unrelated', $this->r('Vibrant International Academy', 'Rajkot')['status']);
    }

    public function test_spelling_with_or_without_spaces_and_a_contained_name(): void
    {
        $this->assertSame('target_exact', $this->r('Nirma Vidyavihar', 'Ahmedabad')['status']);
        $podar = $this->r('Podar World School', 'Vadodara');
        $this->assertSame('target_possible', $podar['status'], 'the name is contained in the account name: a person confirms');
        $this->assertSame(6, $podar['account_id']);
    }

    public function test_generic_words_carry_no_identity(): void
    {
        $this->assertSame([], IdentityResolver::tokens('The International Public School'));
        $this->assertSame(['nirma', 'vidya', 'vihar'], IdentityResolver::tokens('Nirma Vidya Vihar School'));
        $this->assertSame('unrelated', $this->r('Sunshine International School', 'Mahesana')['status']);
        $this->assertSame('unrelated', $this->r('International School', 'Mahesana')['status']);
    }

    public function test_with_no_accounts_everything_is_unrelated(): void
    {
        $r = IdentityResolver::resolve(['name' => 'Anything School', 'location' => 'Surat'], []);
        $this->assertSame('unrelated', $r['status']);
    }

    public function test_a_group_wide_website_does_not_identify_a_branch_but_a_campus_website_does(): void
    {
        $group = [['id' => 1, 'name' => 'Ryan International School', 'city' => 'Rajkot', 'state' => 'Gujarat', 'website' => 'https://www.ryangroup.org', 'website_scope' => 'group', 'crm_ref' => 'LEA82']];
        $campus = [['id' => 1, 'name' => 'Ryan International School', 'city' => 'Rajkot', 'state' => 'Gujarat', 'website' => 'https://www.ryangroup.org', 'website_scope' => 'campus', 'crm_ref' => 'LEA82']];

        // The same group site appears on a page about the Mumbai branch.
        $mumbai = ['name' => 'Ryan International School', 'location' => 'Mumbai', 'website' => 'https://www.ryangroup.org/'];
        $this->assertSame('unrelated', IdentityResolver::resolve($mumbai, $group)['status'], 'the group site is shared by every branch');
        $this->assertSame('target_exact', IdentityResolver::resolve($mumbai, $campus)['status'], 'a site verified for THIS campus identifies it');

        // With the place stated, a group-site match still needs name AND place to agree.
        $rajkot = ['name' => 'Ryan International School', 'location' => 'Rajkot', 'website' => 'https://www.ryangroup.org/'];
        $this->assertSame('target_exact', IdentityResolver::resolve($rajkot, $group)['status']);
        $noPlace = ['name' => 'Some Other Name', 'website' => 'https://www.ryangroup.org/'];
        $this->assertSame('unrelated', IdentityResolver::resolve($noPlace, $group)['status']);
    }
}
