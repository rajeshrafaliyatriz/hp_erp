<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCrmProductApiWorld;
use Tests\TestCase;

/**
 * The old CRM Products form and its CSV import have the limits of the database (slice 15, track WPA1, item 5; audit P2-F open defect (a), gap G-6).
 *
 * CrmProductController gave `nullable|numeric` for the prices and nothing at all for the stock, the weight, the reorder level and the text fields, so a value past its column
 * (a price of 10 trillion, a reorder level of 5 billion, a 200-character SKU) or a negative number reached the database and, under strict mode, was a 500 (a negative stock was
 * simply stored). It now uses CatalogueFields (rules() for the six it always checked, limits() for the rest, the same sets the one-record writer applies), so each of them is a 422
 * that names its field; the single form answers `message` and the import says it per row. A VALID request is answered exactly as before.
 *
 * The values below are written out here on purpose (the column sizes of `SHOW COLUMNS FROM crm_products`, quoted in CatalogueFields::limits()), not read from CatalogueFields:
 * a limit raised there must fail a test here.
 */
class CrmProductFormLimitsTest extends TestCase
{
    use BuildsCrmProductApiWorld;

    private const T = 1;

    private const T2 = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrmProductApiWorld();
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> a field, a value the column cannot hold or the field may not carry, and what the message says about the field */
    public static function tooMuch(): array
    {
        return [
            'unit price above decimal(15,2)' => ['unitPrice', 10000000000000, 'unit price'],
            'cost price above decimal(15,2)' => ['costPrice', 10000000000000, 'cost price'],
            'stock above decimal(15,2)' => ['qtyInStock', 10000000000000, 'qty in stock'],
            'demand above decimal(12,2)' => ['qtyInDemand', 10000000000, 'qty in demand'],
            'reorder level above an unsigned int' => ['reorderLevel', 4294967296, 'reorder level'],
            'weight above decimal(10,3)' => ['weight', 10000000, 'weight'],
            'sku longer than 100' => ['sku', str_repeat('a', 101), 'sku'],
            'category longer than 100' => ['category', str_repeat('a', 101), 'category'],
            'gl account longer than 191' => ['glAccount', str_repeat('a', 192), 'gl account'],
            'currency longer than 10' => ['currency', str_repeat('a', 11), 'currency'],
            'vendor longer than 191' => ['vendor', str_repeat('a', 192), 'vendor'],
            'usage unit longer than 191' => ['usageUnit', str_repeat('a', 192), 'usage unit'],
            'description longer than 16383' => ['description', str_repeat('a', 16384), 'description'],
        ];
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string}> */
    public static function notAllowed(): array
    {
        return [
            'a negative unit price' => ['unitPrice', -0.01, 'unit price'],
            'a negative cost price' => ['costPrice', -1, 'cost price'],
            'a negative stock' => ['qtyInStock', -1, 'qty in stock'],
            'a negative demand' => ['qtyInDemand', -1, 'qty in demand'],
            'a negative reorder level' => ['reorderLevel', -1, 'reorder level'],
            'a reorder level that is not whole' => ['reorderLevel', 1.5, 'reorder level'],
            'a negative weight' => ['weight', -1, 'weight'],
            'a negative tax rate id' => ['taxRateId', -1, 'tax rate id'],
            'a negative assignee' => ['assignedTo', -1, 'assigned to'],
            'a price that is not a number' => ['unitPrice', 'abc', 'unit price'],
            'an active flag that is not a flag' => ['isActive', 'maybe', 'is active'],
            'a commission rate above 100 (the rule the form always had)' => ['commissionRate', 100.5, 'commission rate'],
        ];
    }

    private function body(string $field, mixed $value, array $h): array
    {
        return array_replace(['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => $h['_user']], [$field => $value]);
    }

    // ---------------------------------------------------------------- the single form: create and edit

    #[DataProvider('tooMuch')]
    #[DataProvider('notAllowed')]
    public function test_create_refuses_a_value_the_database_cannot_hold_with_a_422_that_names_the_field(string $field, mixed $value, string $named): void
    {
        $h = $this->h();

        $res = $this->postJson('/api/crm/products', $this->body($field, $value, $h), $this->headers($h));

        $res->assertStatus(422)->assertJsonPath('status', 0);
        $this->assertStringContainsString($named, (string) $res->json('message'), 'the message names the field');
        $this->assertSame(0, DB::table('crm_products')->count(), 'nothing was written');
        $this->assertSame([], DB::table('g2g_product_master')->where('kind', 'item')->get()->all(), 'and no master row was made');
    }

    #[DataProvider('tooMuch')]
    #[DataProvider('notAllowed')]
    public function test_edit_refuses_it_too_and_leaves_the_row_as_it_was(string $field, mixed $value, string $named): void
    {
        $id = $this->crmCreate('Kit');
        $before = (array) DB::table('crm_products')->where('id', $id)->first();
        $h = $this->h();

        $res = $this->putJson("/api/crm/products/{$id}", [$field => $value], $this->headers($h));

        $res->assertStatus(422);
        $this->assertStringContainsString($named, (string) $res->json('message'));
        $this->assertSame($before, (array) DB::table('crm_products')->where('id', $id)->first(), 'the row is unchanged');
    }

    public function test_a_value_exactly_at_each_limit_is_still_accepted_and_the_answer_is_what_it_was(): void
    {
        $h = $this->h();
        $body = [
            'itemType' => 'product', 'name' => 'At the limit', 'assignedTo' => $h['_user'], 'unitPrice' => '9999999999999.99', 'costPrice' => 0, 'qtyInStock' => '9999999999999.99', 'qtyInDemand' => '9999999999.99',
            'reorderLevel' => 4294967295, 'weight' => '9999999.999', 'sku' => str_repeat('a', 100), 'category' => str_repeat('b', 100), 'glAccount' => str_repeat('c', 191), 'currency' => 'INR',
            'vendor' => str_repeat('d', 191), 'usageUnit' => str_repeat('e', 191), 'description' => str_repeat('f', 16383), 'isActive' => true, 'commissionRate' => 100,
        ];

        $res = $this->postJson('/api/crm/products', $body, $this->headers($h))->assertStatus(201);

        $res->assertJsonPath('message', 'Product created.')->assertJsonPath('data.name', 'At the limit');
        $this->assertSame(1, DB::table('crm_products')->count());
    }

    public function test_the_messages_of_the_rules_the_form_always_had_are_unchanged_and_come_first(): void
    {
        $h = $this->h();

        $this->postJson('/api/crm/products', ['itemType' => 'product', 'assignedTo' => $h['_user']], $this->headers($h))->assertStatus(422)->assertJsonPath('message', 'The name field is required.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit'], $this->headers($h))->assertStatus(422)->assertJsonPath('message', 'The assigned to field is required.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => 'x'], $this->headers($h))->assertStatus(422)->assertJsonPath('message', 'The assigned to field must be an integer.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => $h['_user'], 'commissionRate' => 150], $this->headers($h))
            ->assertStatus(422)->assertJsonPath('message', 'The commission rate field must not be greater than 100.');
        // The first message is the first rule that fails, in the order the form always had (name, assignee, unit price, cost price, commission, tax rate) before the new limits.
        $this->postJson('/api/crm/products', ['itemType' => 'product'], $this->headers($h))->assertStatus(422)->assertJsonPath('message', 'The name field is required.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => $h['_user'], 'commissionRate' => 150, 'costPrice' => -1, 'unitPrice' => -1], $this->headers($h))
            ->assertStatus(422)->assertJsonPath('message', 'The unit price field must be at least 0.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => $h['_user'], 'commissionRate' => 150, 'taxRateId' => -1, 'costPrice' => -1], $this->headers($h))
            ->assertStatus(422)->assertJsonPath('message', 'The cost price field must be at least 0.');
        $this->postJson('/api/crm/products', ['itemType' => 'product', 'name' => 'Kit', 'assignedTo' => $h['_user'], 'unitPrice' => -5, 'sku' => str_repeat('a', 101)], $this->headers($h))
            ->assertStatus(422)->assertJsonPath('message', 'The unit price field must be at least 0.');
        $this->assertSame(0, DB::table('crm_products')->count());
    }

    public function test_a_service_has_the_same_limits(): void
    {
        $h = $this->h();

        $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Consulting', 'assignedTo' => $h['_user'], 'unitPrice' => 10000000000000], $this->headers($h))->assertStatus(422);
        $this->postJson('/api/crm/products', ['itemType' => 'service', 'name' => 'Consulting', 'assignedTo' => $h['_user'], 'unitPrice' => 500], $this->headers($h))->assertStatus(201);
    }

    // ---------------------------------------------------------------- the CSV import

    #[DataProvider('tooMuch')]
    #[DataProvider('notAllowed')]
    public function test_the_import_refuses_the_row_that_breaks_a_limit_names_the_field_and_still_imports_the_others(string $field, mixed $value, string $named): void
    {
        $h = $this->h();
        $rows = [
            ['name' => 'Good one', 'assignedTo' => $h['_user'], 'unitPrice' => 10],
            array_replace(['name' => 'Bad one', 'assignedTo' => $h['_user']], [$field => $value]),
            ['name' => 'Good two', 'assignedTo' => $h['_user'], 'sku' => 'G2'],
        ];

        $res = $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => $rows], $this->headers($h))->assertOk();

        $results = $res->json('data.results');
        $this->assertSame([true, false, true], array_column($results, 'ok'));
        $this->assertStringContainsString($named, (string) $results[1]['reason'], 'the row says which field it broke');
        $this->assertSame(['Good one', 'Good two'], DB::table('crm_products')->orderBy('id')->pluck('name')->all(), 'the bad row is the only one left out');
        $this->assertSame(2, $res->json('data.created'));
    }

    public function test_the_import_of_valid_rows_is_answered_as_before(): void
    {
        $h = $this->h();
        $rows = [['name' => 'One', 'assignedTo' => $h['_user'], 'unitPrice' => '12.50', 'qtyInStock' => '3', 'reorderLevel' => '2', 'sku' => 'S1']];

        $this->postJson('/api/crm/products/import', ['itemType' => 'product', 'rows' => $rows], $this->headers($h))
            ->assertOk()->assertJsonPath('message', '1 product(s) imported.')->assertJsonPath('data.created', 1)->assertJsonPath('data.results.0.ok', true);
    }
}
