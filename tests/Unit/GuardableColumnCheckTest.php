<?php

namespace Tests\Unit;

use App\Models\Concerns\SkipsGuardableColumnCheck;
use Tests\TestCase;

/**
 * The live host runs MariaDB 10.1, which has no information_schema.generation_expression. A model with
 * `$guarded` and no `$fillable` makes Eloquent introspect the table with a query that selects it, so
 * create()/fill() throws there ("Unknown column 'generation_expression'") while working fine on 10.11.
 *
 * That is exactly how company research started failing on live with "Signal generation failed": the
 * importer saves offer matches. This test pins every model the research / market-import / portfolio path
 * writes, so a new one cannot be added without the guard.
 */
class GuardableColumnCheckTest extends TestCase
{
    /** @dataProvider models */
    public function test_every_model_the_research_path_writes_skips_the_column_introspection(string $class): void
    {
        $this->assertContains(SkipsGuardableColumnCheck::class, class_uses_recursive($class), "{$class} must use SkipsGuardableColumnCheck (MariaDB 10.1 safety)");

        // And it really does mass-assign without asking the database for its columns.
        $model = new $class();
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        $model->fill(['sub_institute_id' => 1]);
        $this->assertSame([], array_filter($queries, fn ($sql) => str_contains($sql, 'information_schema')), "{$class}::fill() queried information_schema");
    }

    public static function models(): array
    {
        return array_map(fn ($c) => [$c], [
            \App\Domain\Signals\Market\ScanLog::class,
            \App\Domain\Signals\Market\OpportunityEvent::class,
            \App\Domain\Signals\Market\ImportRejection::class,
            \App\Domain\Signals\Market\CompanyAlias::class,
            \App\Domain\Signals\Opportunities\Company::class,
            \App\Domain\Signals\Opportunities\CompanyOpportunity::class,
            \App\Domain\Signals\Opportunities\ResearchRun::class,
            \App\Domain\Signals\Opportunities\ResearchSource::class,
            \App\Domain\Portfolio\OpportunityMatch::class,
            \App\Domain\Portfolio\ProductOffer::class,
            \App\Domain\Portfolio\Partner::class,
        ]);
    }
}
