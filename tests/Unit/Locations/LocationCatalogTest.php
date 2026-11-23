<?php

namespace Tests\Unit\Locations;

use App\Domain\Signals\Locations\LocationCatalog;
use App\Domain\Signals\Support\RegionInvalid;
use App\Domain\Signals\Support\RegionScope;
use PHPUnit\Framework\TestCase;

/**
 * The location catalogue and its validation, with no framework and no database: it reads the data files in resources/data/locations only.
 * (The custom-places lookup quietly does nothing without a database, which is what these tests rely on.)
 */
class LocationCatalogTest extends TestCase
{
    private LocationCatalog $c;

    protected function setUp(): void
    {
        $this->c = new LocationCatalog();
    }

    private function names(array $rows): array
    {
        return array_map(fn ($r) => $r['name'], $rows);
    }

    // ── countries ────────────────────────────────────────────────────────────

    public function test_the_catalogue_has_a_complete_country_list_not_a_handful(): void
    {
        $countries = $this->c->countries();

        $this->assertGreaterThanOrEqual(240, count($countries));
        foreach (['India', 'Singapore', 'United States', 'United Kingdom', 'Japan', 'Brazil', 'Kenya', 'New Zealand', 'Germany', 'United Arab Emirates'] as $name) {
            $this->assertContains($name, $this->names($countries), $name);
        }
        $india = $this->c->country('India');
        $this->assertSame(['IN', 'IND'], [$india['code'], $india['iso3']]);
        $this->assertNotEmpty($india['emoji'], 'a flag is available');
    }

    public function test_a_country_is_found_by_name_code_or_alpha3_ignoring_case_accents_and_spacing(): void
    {
        foreach (['singapore', 'SINGAPORE', '  Singapore ', 'sg', 'SGP'] as $q) {
            $this->assertSame('SG', $this->c->country($q)['code'], $q);
        }
        $this->assertSame('CI', $this->c->country("Côte d'Ivoire")['code'] ?? $this->c->country('Ivory Coast')['code']);
        $this->assertSame('US', $this->c->country('USA')['code']);
        $this->assertSame('GB', $this->c->country('UK')['code']);
        $this->assertNull($this->c->country('Atlantis'));
        $this->assertNull($this->c->country(''));
    }

    // ── states ───────────────────────────────────────────────────────────────

    public function test_india_has_its_states_and_union_territories_from_the_data_with_codes(): void
    {
        $states = $this->c->states('IN');
        $names = $this->names($states);

        $this->assertGreaterThanOrEqual(35, count($states));
        foreach (['Gujarat', 'Maharashtra', 'Delhi', 'Ladakh', 'Andaman and Nicobar Islands', 'Tamil Nadu'] as $n) {
            $this->assertContains($n, $names, $n);
        }
        $this->assertSame('GJ', $this->c->state('IN', 'Gujarat')['code']);
        $this->assertSame('Gujarat', $this->c->state('IN', 'gj')['name'], 'by code too');
    }

    public function test_singapore_shows_the_divisions_its_data_source_has_and_nothing_else(): void
    {
        $this->assertSame(['Central Singapore', 'North East', 'North West', 'South East', 'South West'], $this->names($this->c->states('SG')));
    }

    public function test_a_country_without_states_in_the_data_is_reported_as_such_and_nothing_is_invented(): void
    {
        $country = $this->c->country('Antarctica');

        $this->assertFalse($country['has_states']);
        $this->assertSame([], $this->c->states('AQ'));
        $this->assertCount(0, $this->c->cities('AQ', null)['items']);
    }

    // ── cities ───────────────────────────────────────────────────────────────

    public function test_gujarat_lists_surat_and_only_gujarat_cities(): void
    {
        $gujarat = $this->names($this->c->cities('IN', 'GJ', null, 500)['items']);
        $maharashtra = $this->names($this->c->cities('IN', 'MH', null, 500)['items']);

        $this->assertContains('Surat', $gujarat);
        $this->assertContains('Ahmedabad', $gujarat);
        $this->assertNotContains('Mumbai', $gujarat, "another state's city");
        $this->assertContains('Mumbai', $maharashtra);
        $this->assertNotContains('Surat', $maharashtra);
        $this->assertEmpty(array_intersect($gujarat, ['Houston', 'Singapore']), "another country's cities");
    }

    public function test_city_search_is_case_insensitive_starts_first_and_bounded(): void
    {
        $r = $this->c->cities('IN', 'GJ', 'SUR', 5);

        $this->assertSame('Surat', $r['items'][0]['name'], 'a name that begins with what was typed comes first');
        $this->assertLessThanOrEqual(5, count($r['items']));
        $big = $this->c->cities('US', 'CA', null, 20);
        $this->assertCount(20, $big['items']);
        $this->assertTrue($big['truncated']);
        $this->assertGreaterThan(20, $big['total']);
        $this->assertCount(500, $this->c->cities('US', 'CA', null, 100000)['items'], 'never more than 500 at a time');
    }

    // ── validation ───────────────────────────────────────────────────────────

    public function test_a_valid_country_state_city_is_completed_with_the_catalogues_codes(): void
    {
        $r = $this->c->resolve(['country' => 'india', 'state' => 'GUJARAT', 'city' => 'surat']);

        $this->assertSame([], $r['problems']);
        $this->assertSame(['India', 'Gujarat', 'Surat', 'IN', 'GJ', true, 'dataset'], [
            $r['selection']['country'], $r['selection']['state'], $r['selection']['city'], $r['selection']['country_code'], $r['selection']['state_code'], $r['selection']['verified'], $r['selection']['source'],
        ]);
        $this->assertIsInt($r['selection']['city_id']);
        // and by codes and id alone
        $byCodes = $this->c->resolve(['country_code' => 'IN', 'state_code' => 'GJ', 'city_id' => $r['selection']['city_id']]);
        $this->assertSame('Surat', $byCodes['selection']['city']);
    }

    public function test_a_country_alone_is_valid(): void
    {
        $r = $this->c->resolve(['country' => 'Singapore']);

        $this->assertSame([], $r['problems']);
        $this->assertSame(['Singapore', null, null, 'SG'], [$r['selection']['country'], $r['selection']['state'], $r['selection']['city'], $r['selection']['country_code']]);
    }

    public function test_a_typed_state_is_accepted_as_manual_text_and_a_known_one_is_completed_with_its_code(): void
    {
        $manual = $this->c->resolve(['country' => 'India', 'state' => 'Texas']);
        $this->assertSame([], $manual['problems']);
        $this->assertSame(['Texas', null, 'manual', false], [$manual['selection']['state'], $manual['selection']['state_code'], $manual['selection']['source'], $manual['selection']['verified']]);
        $known = $this->c->resolve(['country' => 'India', 'state' => 'gujarat']);
        $this->assertSame(['Gujarat', 'GJ', 'dataset'], [$known['selection']['state'], $known['selection']['state_code'], $known['selection']['source']]);
        $this->assertNotEmpty($this->c->resolve(['country_code' => 'IN', 'state_code' => 'CA'])['problems'], 'a state CODE that is not the country\'s is still refused');
    }

    public function test_a_typed_city_is_accepted_with_or_without_a_state_and_a_known_one_gets_its_id(): void
    {
        $mumbai = $this->c->resolve(['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai']);
        $this->assertSame([], $mumbai['problems']);
        $this->assertIsInt($mumbai['selection']['city_id']);
        $manual = $this->c->resolve(['country' => 'India', 'state' => 'Gujarat', 'city' => 'Houston']);
        $this->assertSame([], $manual['problems']);
        $this->assertSame(['Houston', null, 'manual'], [$manual['selection']['city'], $manual['selection']['city_id'], $manual['selection']['source']]);
        $this->assertSame([], $this->c->resolve(['country' => 'India', 'city' => 'Surat'])['problems'], 'a city without a state');
    }

    public function test_conflicting_country_codes_and_names_are_still_rejected(): void
    {
        $this->assertNotEmpty($this->c->resolve(['country' => 'Singapore', 'country_code' => 'IN'])['problems']);
        $this->assertNotEmpty($this->c->resolve(['country_code' => 'ZZ'])['problems'], 'not a country code');
        $this->assertNotEmpty($this->c->resolve(['country_code' => 'IN', 'city_id' => 1])['problems'], 'an id that is not a city of the chosen place');
    }

    public function test_a_country_without_state_data_still_takes_a_typed_state_and_city_as_manual_text(): void
    {
        $r = $this->c->resolve(['country' => 'Antarctica', 'state' => 'Somewhere', 'city' => 'Base']);

        $this->assertSame([], $r['problems']);
        $this->assertSame(['Somewhere', 'Base', false, 'manual'], [$r['selection']['state'], $r['selection']['city'], $r['selection']['verified'], $r['selection']['source']]);
        $this->assertSame([], $this->c->resolve(['country' => 'Antarctica'])['problems']);
    }

    public function test_a_place_the_catalogue_does_not_know_is_kept_as_typed_and_never_becomes_india(): void
    {
        $r = $this->c->resolve(['country' => 'Atlantis', 'state' => 'Deep', 'city' => 'Poseidonis']);

        $this->assertSame([], $r['problems']);
        $this->assertSame(['Atlantis', 'Deep', 'Poseidonis', null, false, 'unverified'], [
            $r['selection']['country'], $r['selection']['state'], $r['selection']['city'], $r['selection']['country_code'], $r['selection']['verified'], $r['selection']['source'],
        ]);
    }

    public function test_places_saved_earlier_with_any_state_or_city_text_are_still_accepted(): void
    {
        $r = $this->c->resolve(['country' => 'Singapore', 'state' => 'Central Region', 'city' => 'Singapore']);

        $this->assertSame([], $r['problems']);
        $this->assertSame(['Central Region', 'Singapore', 'SG'], [$r['selection']['state'], $r['selection']['city'], $r['selection']['country_code']]);
    }

    // ── through RegionScope (what every endpoint uses) ───────────────────────

    public function test_region_scope_stores_canonical_names_in_the_unchanged_shape_and_keeps_the_codes_separately(): void
    {
        $scope = RegionScope::fromInput([['country' => 'india', 'state' => 'gujarat', 'city' => 'SURAT']]);

        $this->assertSame([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']], $scope->selections(), 'exactly the shape runs and opportunities are matched by');
        $this->assertSame('GJ', $scope->structured()[0]['state_code']);
        $this->assertSame(['Surat'], $scope->terms());
    }

    public function test_region_scope_refuses_only_conflicting_codes_and_keeps_typed_state_and_city(): void
    {
        try {
            RegionScope::fromInput([['country_code' => 'IN', 'state_code' => 'CA']]);
            $this->fail('expected a rejection');
        } catch (RegionInvalid $e) {
            $this->assertStringContainsString('region 1:', $e->problems[0]);
        }
        $scope = RegionScope::fromInput([['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai'], ['country' => 'Singapore', 'state' => 'My Region', 'city' => 'Anywhere']]);
        $this->assertSame([['country' => 'India', 'state' => 'Maharashtra', 'city' => 'Mumbai'], ['country' => 'Singapore', 'state' => 'My Region', 'city' => 'Anywhere']], $scope->selections());
    }

    public function test_region_scope_accepts_a_country_picked_by_code_alone_and_two_places(): void
    {
        $scope = RegionScope::fromInput([['country_code' => 'SG'], ['country' => 'India', 'state' => 'Gujarat']]);

        $this->assertSame([['country' => 'Singapore', 'state' => null, 'city' => null], ['country' => 'India', 'state' => 'Gujarat', 'city' => null]], $scope->selections());
    }

    public function test_changing_the_country_changes_the_whole_place_there_is_no_leftover_state_or_city(): void
    {
        $india = RegionScope::fromInput([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']])->selections()[0];
        $singapore = RegionScope::fromInput([['country' => 'Singapore']])->selections()[0];

        $this->assertSame(['India', 'Gujarat', 'Surat'], array_values($india));
        $this->assertSame(['Singapore', null, null], array_values($singapore));
    }

    public function test_fold_ignores_case_accents_and_extra_spaces(): void
    {
        $this->assertSame('cote d\'ivoire', LocationCatalog::fold("  CÔTE   d'Ivoire "));
        $this->assertSame('sao paulo', LocationCatalog::fold('São Paulo'));
    }

    public function test_the_data_files_carry_their_licence_notice_and_version(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/resources/data/locations/NOTICE.md');
        $meta = $this->c->meta();
        $this->assertSame('ODbL-1.0', $meta['licence']);
        $this->assertSame(250, $meta['counts']['countries']);
        $this->assertNotEmpty($meta['version']);
    }

    public function test_the_chosen_place_reaches_the_search_query_and_the_providers_country_bias(): void
    {
        $surat = RegionScope::fromInput([['country_code' => 'IN', 'state_code' => 'GJ', 'city' => 'Surat']]);
        $singapore = RegionScope::fromInput([['country' => 'Singapore']]);

        $this->assertSame('school ERP tender "Surat"', $surat->applyToQuery('school ERP tender', []), 'the city is what the query is aimed at');
        $this->assertSame('india', $surat->singleCountry(), 'the provider is biased to the country');
        $this->assertSame('school ERP tender "Singapore"', $singapore->applyToQuery('school ERP tender', []));
        $this->assertSame('singapore', $singapore->singleCountry());
        // the state or city is not left to the provider: the source itself has to name it for a signal to qualify
        $this->assertSame('outside', $surat->evidence('Pune', ' a school in pune announced a tender ')['status']);
    }
}
