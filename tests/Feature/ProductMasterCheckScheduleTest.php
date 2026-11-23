<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The daily product check is on the schedule (slice 15, audit item G-D1): read only, named connection, skipped on a host without the Product Master.
 */
class ProductMasterCheckScheduleTest extends TestCase
{
    private function events()
    {
        Artisan::call('list');   // loads routes/console.php, which registers the schedule

        return collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'product-master:check'));
    }

    public function test_the_check_is_scheduled_once_a_day_on_the_named_default_connection_and_logs_what_it_finds(): void
    {
        $events = $this->events();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('40 1 * * *', $event->expression);
        $this->assertStringContainsString('--database=' . config('database.default'), (string) $event->command, 'the connection is named, so a host other than the dev one is not refused');
        $this->assertStringNotContainsString('--snapshot', (string) $event->command, 'it writes no census file');
        $this->assertTrue($event->withoutOverlapping, 'one run at a time');
        $this->assertNotEmpty($event->output, 'its output is kept, so a failure can log the counts');
    }

    public function test_the_check_is_skipped_on_a_host_without_the_product_master_table(): void
    {
        $event = $this->events()->first();

        if (Schema::hasTable('g2g_product_master')) {
            Schema::drop('g2g_product_master');
        }
        $this->assertFalse($event->filtersPass(app()), 'skipped without the table');

        Schema::create('g2g_product_master', function ($table) {
            $table->id();
        });
        $this->assertTrue($event->filtersPass(app()), 'runs once the table exists');
    }
}
