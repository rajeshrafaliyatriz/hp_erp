<?php

namespace Tests\Unit;

use App\Domain\Signals\Icp\IcpPack;
use App\Domain\Signals\Support\RegionInvalid;
use App\Domain\Signals\Support\RegionScope;
use Tests\TestCase;

/** A request-scoped geography: validation, query and prompt aiming, source-text evidence, and that nothing saved is changed. No database. */
class RegionScopeTest extends TestCase
{
    private function scope(array $in): RegionScope
    {
        return RegionScope::fromInput($in);
    }

    private function icp(): array
    {
        return [
            'label' => 'ScholarClone K-12', 'industry_label' => 'private K-12 schools', 'regions' => ['Gujarat', 'Maharashtra'], 'priority_cities' => ['Ahmedabad'],
            'parts' => ['school_erp' => 'x'], 'must_have' => [['field' => 'state', 'op' => 'in_regions', 'label' => 'outside the target regions']],
            'queries' => [
                ['id' => 'a', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'school "new campus" admissions 2027 Gujarat OR Maharashtra'],
                ['id' => 'b', 'source' => 'tender', 'topic' => 'general', 'days' => 90, 'q' => 'tender "school ERP" Gujarat'],
            ],
        ];
    }

    // ── input ────────────────────────────────────────────────────────────────

    public function test_nothing_or_all_means_all_regions_and_changes_nothing(): void
    {
        foreach ([null, [], '', 'all'] as $none) {
            $this->assertNull(RegionScope::fromInput($none));
        }
    }

    public function test_country_only_country_state_and_country_state_city_are_accepted_and_labelled(): void
    {
        $this->assertSame('India', $this->scope([['country' => 'India']])->label());
        $this->assertSame('Gujarat, India', $this->scope([['country' => 'India', 'state' => 'Gujarat']])->label());
        $s = $this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat'], ['country' => 'India', 'state' => 'Kerala']]);
        $this->assertSame('Surat, Gujarat, India; Kerala, India', $s->label());
        $this->assertSame(['Surat', 'Kerala'], $s->terms(), 'the most specific place of each selection');
    }

    public function test_any_country_works_no_list_of_countries_is_built_in(): void
    {
        $this->assertSame('Texas, United States', $this->scope([['country' => 'United States', 'state' => 'Texas']])->label());
        $this->assertSame('Bavaria, Germany', $this->scope([['country' => 'Germany', 'state' => 'Bavaria']])->label());
    }

    public function test_inconsistent_or_unsafe_input_is_refused_with_reasons(): void
    {
        $bad = [
            'a city without a state' => [['country' => 'India', 'city' => 'Surat']],
            'no country' => [['state' => 'Gujarat']],
            'not a list' => ['country' => 'India'],
            'an item that is not an object' => ['India'],
            'too long' => [['country' => str_repeat('a', 81)]],
            'too short' => [['country' => 'I']],
            'markup' => [['country' => '<script>alert(1)</script>']],
            'control characters' => [['country' => "Ind\x00ia"]],
            'a number' => [['country' => 123]],
            'more than five places' => array_map(fn ($i) => ['country' => "Country{$i}x"], range(1, 6)),
        ];
        foreach ($bad as $why => $input) {
            try {
                RegionScope::fromInput($input);
                $this->fail("{$why} should be refused");
            } catch (RegionInvalid $e) {
                $this->assertNotSame([], $e->problems, $why);
            }
        }
    }

    public function test_the_same_place_twice_is_one_place_and_spaces_are_tidied(): void
    {
        $s = $this->scope([['country' => ' India ', 'state' => 'Tamil   Nadu'], ['country' => 'india', 'state' => 'tamil nadu']]);
        $this->assertCount(1, $s->selections());
        $this->assertSame('Tamil Nadu, India', $s->label());
    }

    // ── the query is aimed at the region ─────────────────────────────────────

    public function test_a_region_replaces_the_icps_own_region_words_in_a_query_and_leaves_no_dangling_or(): void
    {
        $q = $this->scope([['country' => 'India', 'state' => 'Kerala']])->applyToQuery('school "new campus" admissions 2027 Gujarat OR Maharashtra', ['Gujarat', 'Maharashtra']);
        $this->assertSame('school "new campus" admissions 2027 "Kerala"', $q);
        $this->assertDoesNotMatchRegularExpression('/\bOR\s*$|\bOR\s+OR\b|\(\s*\)/', $q);

        $city = $this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']])->applyToQuery('tender "school ERP" Gujarat', ['Gujarat']);
        $this->assertSame('tender "school ERP" "Surat"', $city);
    }

    public function test_several_places_become_one_or_group_and_the_query_stays_within_the_provider_length(): void
    {
        $s = $this->scope([['country' => 'India', 'state' => 'Kerala'], ['country' => 'India', 'state' => 'Goa']]);
        $this->assertStringEndsWith('("Kerala" OR "Goa")', $s->applyToQuery('school ERP', []));
        $this->assertLessThanOrEqual(380, mb_strlen($s->applyToQuery(str_repeat('word ', 200), [])));
    }

    // ── the pack is aimed, the saved ICP is not changed ──────────────────────

    public function test_the_scoped_pack_has_the_new_region_in_queries_and_prompt_and_the_original_is_untouched(): void
    {
        $pack = new IcpPack('scholar_k12', $this->icp());
        $scoped = $pack->withRegionScope($this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']]));

        $this->assertSame(['Gujarat', 'Maharashtra'], $pack->config()['regions'], 'the saved ICP regions are unchanged');
        $this->assertSame(['Ahmedabad'], $pack->config()['priority_cities']);
        $this->assertStringContainsString('Gujarat OR Maharashtra', $pack->config()['queries'][0]['q']);
        $this->assertStringNotContainsString('REGION FOCUS', $pack->promptBlock());

        $this->assertSame(['Surat'], $scoped->config()['regions']);
        $this->assertSame(['Surat'], $scoped->config()['priority_cities']);
        foreach ($scoped->config()['queries'] as $q) {
            $this->assertStringContainsString('"Surat"', $q['q']);
            $this->assertStringNotContainsString('Maharashtra', $q['q']);
        }
        $this->assertSame(['a', 'b'], array_column($scoped->config()['queries'], 'id'), 'no query is added or dropped: the budget is unchanged');
        $prompt = $scoped->promptBlock();
        $this->assertStringContainsString('REGION FOCUS', $prompt);
        $this->assertStringContainsString('Surat, Gujarat, India', $prompt);
        $this->assertStringContainsString('never infer it from a website', $prompt);
        // Only the "inside the target regions" rule is left out of the planning COPY (it would contradict the request); the original pack, which
        // qualification uses, keeps every rule.
        $this->assertSame($pack->config()['must_have'], (new IcpPack('scholar_k12', $this->icp()))->config()['must_have']);
        $this->assertSame([], $scoped->config()['must_have']);
    }

    public function test_changing_the_region_changes_the_plan_and_all_regions_is_the_original_plan(): void
    {
        $pack = new IcpPack('p', $this->icp());
        $a = $pack->withRegionScope($this->scope([['country' => 'India', 'state' => 'Kerala']]))->config()['queries'];
        $b = $pack->withRegionScope($this->scope([['country' => 'India', 'state' => 'Goa']]))->config()['queries'];
        $this->assertNotSame($a, $b);
        $this->assertSame($this->icp()['queries'], $pack->config()['queries'], 'All Regions is the existing behaviour');
    }

    public function test_a_scoped_pack_of_one_product_never_leaks_into_another(): void
    {
        $one = (new IcpPack('one', $this->icp()))->withRegionScope($this->scope([['country' => 'India', 'state' => 'Kerala']]));
        $other = new IcpPack('two', ['label' => 'Other', 'regions' => ['Texas'], 'queries' => [['id' => 'z', 'source' => 'news', 'topic' => 'news', 'days' => 30, 'q' => 'x Texas']]]);
        $this->assertSame(['Texas'], $other->config()['regions']);
        $this->assertArrayNotHasKey('region_scope', $other->config());
        $this->assertStringNotContainsString('Kerala', $other->promptBlock());
    }

    // ── evidence: only the source's own text counts ──────────────────────────

    public function test_a_city_or_state_must_be_named_in_the_source_text(): void
    {
        $s = $this->scope([['country' => 'India', 'state' => 'Gujarat', 'city' => 'Surat']]);
        $ok = $s->evidence('Surat', 'Podar School in Surat announced a new campus and is hiring an ERP administrator.');
        $this->assertSame('confirmed', $ok['status']);
        $this->assertSame('Surat', $ok['matched']);
        $this->assertSame('source_text', $ok['basis']);

        $no = $s->evidence('Surat', 'Podar School announced a new campus. Applications close on 5 November.');
        $this->assertSame('outside', $no['status'], 'the model saying Surat is not evidence');
        $this->assertSame('outside', $s->evidence(null, 'a school in Suratkal opened a campus')['status'], 'a longer word is not the place');
    }

    public function test_a_country_only_request_the_source_does_not_name_is_kept_but_marked_unconfirmed(): void
    {
        $s = $this->scope([['country' => 'India']]);
        $this->assertSame('confirmed', $s->evidence(null, 'A school group in India is replacing its ERP.')['status']);
        $u = $s->evidence('Pune', 'A school in Pune is replacing its ERP.');
        $this->assertSame('unconfirmed', $u['status']);
        $this->assertNull($u['matched']);
    }

    public function test_with_several_places_one_confirmed_place_is_enough(): void
    {
        $s = $this->scope([['country' => 'India', 'state' => 'Kerala'], ['country' => 'India', 'state' => 'Goa']]);
        $this->assertSame('confirmed', $s->evidence(null, 'A Goa school trust invited tenders.')['status']);
        $this->assertSame('outside', $s->evidence(null, 'A Punjab school trust invited tenders.')['status']);
    }

    public function test_the_domain_of_the_source_is_never_read(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Signals/Support/RegionScope.php');
        $this->assertStringNotContainsString('parse_url', $src);
        $this->assertStringNotContainsString('PHP_URL_HOST', $src);
    }
}
