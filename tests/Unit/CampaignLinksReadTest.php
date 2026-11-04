<?php

namespace Tests\Unit;

use App\Domain\Campaigns\CampaignLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How slice 9 reads the optional link a caller names (`product_master_id`, `campaign_id`, `crm_campaign_id`): absent, empty, a whole number, or malformed. A JSON
 * request turns '' into null before it gets here, but a CSV import row (Request::create) does not, so the empty string is read directly.
 */
class CampaignLinksReadTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: array{present: bool, id: ?int, malformed: bool}}> */
    public static function inputs(): array
    {
        return [
            'absent' => [[], ['present' => false, 'id' => null, 'malformed' => false]],
            'other keys only' => [['name' => 'x'], ['present' => false, 'id' => null, 'malformed' => false]],
            'null means none' => [['product_master_id' => null], ['present' => true, 'id' => null, 'malformed' => false]],
            'empty string means none' => [['product_master_id' => ''], ['present' => true, 'id' => null, 'malformed' => false]],
            'an integer' => [['product_master_id' => 12], ['present' => true, 'id' => 12, 'malformed' => false]],
            'a digit string' => [['product_master_id' => '12'], ['present' => true, 'id' => 12, 'malformed' => false]],
            'the camel spelling' => [['productMasterId' => 7], ['present' => true, 'id' => 7, 'malformed' => false]],
            'snake wins over camel' => [['product_master_id' => 3, 'productMasterId' => 7], ['present' => true, 'id' => 3, 'malformed' => false]],
            'an empty snake still wins over a camel value' => [['product_master_id' => null, 'productMasterId' => 7], ['present' => true, 'id' => null, 'malformed' => false]],
            'zero' => [['product_master_id' => 0], ['present' => true, 'id' => null, 'malformed' => true]],
            'zero as text' => [['product_master_id' => '0'], ['present' => true, 'id' => null, 'malformed' => true]],
            'negative' => [['product_master_id' => -4], ['present' => true, 'id' => null, 'malformed' => true]],
            'negative text' => [['product_master_id' => '-4'], ['present' => true, 'id' => null, 'malformed' => true]],
            'a float' => [['product_master_id' => 1.5], ['present' => true, 'id' => null, 'malformed' => true]],
            'a float as text' => [['product_master_id' => '1.5'], ['present' => true, 'id' => null, 'malformed' => true]],
            'a word' => [['product_master_id' => 'abc'], ['present' => true, 'id' => null, 'malformed' => true]],
            'a boolean' => [['product_master_id' => true], ['present' => true, 'id' => null, 'malformed' => true]],
            'an array' => [['product_master_id' => [1]], ['present' => true, 'id' => null, 'malformed' => true]],
            'spaces around digits' => [['product_master_id' => ' 12 '], ['present' => true, 'id' => null, 'malformed' => true]],
        ];
    }

    /** @param array<string, mixed> $input @param array{present: bool, id: ?int, malformed: bool} $expected */
    #[DataProvider('inputs')]
    public function test_the_link_is_read_as_absent_empty_a_whole_number_or_malformed(array $input, array $expected): void
    {
        $this->assertSame($expected, CampaignLinks::read($input, 'product_master_id'));
    }

    public function test_the_key_is_camel_cased_from_whatever_snake_case_key_is_asked_for(): void
    {
        $this->assertSame(['present' => true, 'id' => 5, 'malformed' => false], CampaignLinks::read(['campaignId' => 5], 'campaign_id'));
        $this->assertSame(['present' => true, 'id' => 6, 'malformed' => false], CampaignLinks::read(['crmCampaignId' => 6], 'crm_campaign_id'));
    }
}
