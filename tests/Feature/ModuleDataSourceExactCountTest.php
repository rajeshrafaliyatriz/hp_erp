<?php

namespace Tests\Feature;

use App\Domain\AI\Reports\ModuleDataSourceCatalog;
use App\Services\Ai\AiRequestScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A capped read must not be reported as a total.
 *
 * The assistant is handed at most N rows of a source. Counting those rows ("498 pending") gives the
 * size of the sample, not of the data - a defect found against the live database, where the real
 * figure was 891. The catalogue therefore counts the full source in the database when it truncates,
 * and does so under the same organisation scope as the rows.
 */
class ModuleDataSourceExactCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The catalogue's queries use MySQL's CONCAT; SQLite has none.
        DB::connection()->getPdo()->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));

        Schema::create('tbluser', function ($t) {
            $t->integer('id')->primary();
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
        });
        Schema::create('hrms_leave_types', function ($t) {
            $t->integer('id')->primary();
            $t->string('leave_type');
        });
        Schema::create('hrms_emp_leaves', function ($t) {
            $t->integer('id')->primary();
            $t->integer('user_id');
            $t->integer('leave_type_id');
            $t->date('from_date');
            $t->date('to_date');
            $t->decimal('chargeable_days', 5, 2)->default(1);
            $t->string('status');
            $t->string('approved_by')->nullable();
            $t->integer('sub_institute_id');
            $t->timestamp('deleted_at')->nullable();
        });

        DB::table('hrms_leave_types')->insert(['id' => 1, 'leave_type' => 'Annual Leave']);
        DB::table('tbluser')->insert(['id' => 1, 'first_name' => 'A', 'last_name' => 'B']);

        // Organisation 3: 4 pending + 2 approved. Organisation 1: 2 pending.
        $rows = [];
        foreach ([3 => ['pending' => 4, 'approved' => 2], 1 => ['pending' => 2]] as $institute => $byStatus) {
            foreach ($byStatus as $status => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $rows[] = [
                        'id' => count($rows) + 1, 'user_id' => 1, 'leave_type_id' => 1,
                        'from_date' => '2026-08-0' . (count($rows) + 1), 'to_date' => '2026-08-0' . (count($rows) + 1),
                        'status' => $status, 'sub_institute_id' => $institute,
                    ];
                }
            }
        }
        DB::table('hrms_emp_leaves')->insert($rows);
    }

    private function scope(int $institute): AiRequestScope
    {
        return new AiRequestScope(1, 'admin', $institute, [$institute], 1, null, true, false);
    }

    public function test_a_truncated_read_reports_the_real_size(): void
    {
        $result = app(ModuleDataSourceCatalog::class)->run('hrms.leave_requests', $this->scope(3), ['limit' => 3]);

        $this->assertTrue($result['truncated']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(6, $result['exact_total']);
    }

    public function test_a_complete_read_carries_no_exact_total(): void
    {
        $result = app(ModuleDataSourceCatalog::class)->run('hrms.leave_requests', $this->scope(3), ['limit' => 50]);

        $this->assertFalse($result['truncated']);
        $this->assertSame(6, $result['total']);
        $this->assertArrayNotHasKey('exact_total', $result);
    }

    public function test_exact_counts_cover_every_row_not_just_the_sample(): void
    {
        $counts = app(ModuleDataSourceCatalog::class)->exactCounts('hrms.leave_requests', $this->scope(3), ['status']);

        $this->assertSame(
            [['value' => 'pending', 'count' => 4], ['value' => 'approved', 'count' => 2]],
            $counts['status']
        );
    }

    public function test_counts_stay_inside_the_callers_organisation(): void
    {
        $catalog = app(ModuleDataSourceCatalog::class);

        $other = $catalog->run('hrms.leave_requests', $this->scope(1), ['limit' => 1]);
        $this->assertSame(2, $other['exact_total']);

        // Organisation 1 has a single status, so there is nothing to split and no organisation-3 value appears.
        $this->assertSame([], $catalog->exactCounts('hrms.leave_requests', $this->scope(1), ['status']));
    }

    public function test_a_column_name_is_never_trusted_as_sql(): void
    {
        $counts = app(ModuleDataSourceCatalog::class)->exactCounts(
            'hrms.leave_requests',
            $this->scope(3),
            ['status`; DROP TABLE tbluser; --', 'not_a_column']
        );

        $this->assertSame([], $counts);
        $this->assertTrue(Schema::hasTable('tbluser'));
    }
}
