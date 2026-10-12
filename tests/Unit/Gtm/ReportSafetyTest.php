<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\Reports\ReportService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportSafetyTest extends TestCase
{
    #[DataProvider('cells')]
    public function test_a_spreadsheet_cannot_execute_a_cell_value(mixed $in, string $out): void
    {
        $this->assertSame($out, ReportService::safeCell($in));
    }

    public static function cells(): array
    {
        return [
            'formula' => ['=HYPERLINK("http://evil","x")', "'=HYPERLINK(\"http://evil\",\"x\")"],
            'plus' => ['+1+1', "'+1+1"], 'at' => ['@SUM(A1)', "'@SUM(A1)"], 'minus text' => ['-cmd|calc', "'-cmd|calc"],
            'leading tab' => ["\t=1+1", "'\t=1+1"], 'leading CR' => ["\r=1", "'\n=1"],
            'a real negative number is left alone' => [-5, '-5'], 'a negative numeric string is left alone' => ['-12.5', '-12.5'],
            'ordinary text' => ['Acme Corp', 'Acme Corp'], 'a = in the middle is fine' => ['a=b', 'a=b'],
            'null' => [null, ''], 'true' => [true, 'yes'], 'false' => [false, 'no'], 'float' => [12.5, '12.5'], 'array is JSON' => [['a' => 1], '{"a":1}'],
            'CRLF normalised' => ["a\r\nb", "a\nb"],
        ];
    }

    private function svc(): ReportService
    {
        return app(ReportService::class);
    }

    public function test_filters_are_validated_and_unknown_ones_are_refused_not_ignored(): void
    {
        [$clean, $errors] = $this->svc()->filters('accounts', ['stage' => 'target', 'min_icp' => '50', 'date_from' => '2026-01-01', 'date_to' => '2026-02-01', 'page' => 3, 'format' => 'csv']);
        $this->assertSame([], $errors);
        $this->assertSame(['stage' => 'target', 'min_icp' => 50, 'date_from' => '2026-01-01', 'date_to' => '2026-02-01'], $clean, 'paging and format are not filters');

        foreach ([['sql' => '1'], ['stage' => 'dreaming'], ['min_icp' => 'high'], ['date_from' => '01/02/2026'], ['date_from' => '2026-02-30'], ['owner_user_id' => '1 OR 1=1']] as $bad) {
            [, $e] = $this->svc()->filters('accounts', $bad);
            $this->assertNotEmpty($e, json_encode($bad).' must be refused');
        }
    }

    public function test_the_date_range_must_be_in_order(): void
    {
        [, $e] = $this->svc()->filters('accounts', ['date_from' => '2026-03-01', 'date_to' => '2026-01-01']);
        $this->assertContains('date_to cannot be before date_from.', $e);
    }

    public function test_a_filter_belongs_to_a_report_only_if_that_report_declares_it(): void
    {
        [, $e] = $this->svc()->filters('contacts', ['min_icp' => '10']);
        $this->assertNotEmpty($e, 'min_icp is an accounts filter, not a contacts filter');
        [, $e] = $this->svc()->filters('pipeline', ['currency' => 'inr']);
        $this->assertNotEmpty($e, 'currency must be a capitalised 3-letter code');
        [$c, $e] = $this->svc()->filters('pipeline', ['currency' => 'INR']);
        $this->assertSame([], $e);
        $this->assertSame(['currency' => 'INR'], $c);
    }

    public function test_the_catalogue_hides_admin_only_reports_from_everyone_else(): void
    {
        $this->assertArrayHasKey('audit', $this->svc()->catalogue(true));
        $this->assertArrayNotHasKey('audit', $this->svc()->catalogue(false));
        $this->assertCount(16, $this->svc()->catalogue(true));
        $this->assertTrue($this->svc()->catalogue(false)['contacts']['pii']);
        $this->assertTrue($this->svc()->catalogue(false)['outreach']['pii']);
        $this->assertFalse($this->svc()->catalogue(false)['pipeline']['pii']);
    }
}
