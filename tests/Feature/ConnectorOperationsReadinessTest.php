<?php

namespace Tests\Feature;

use App\Jobs\SyncDataConnectorJob;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * What the code itself guarantees about running connector syncs: that the scheduled command is registered every
 * minute, and that the jobs it dispatches target the queue and limits the documented worker command uses.
 *
 * This proves the CONFIGURATION IS CONSISTENT. It does not prove a scheduler or worker is running anywhere:
 * phpunit uses QUEUE_CONNECTION=sync and never starts a process. That needs staging/production evidence.
 */
class ConnectorOperationsReadinessTest extends TestCase
{
    public function test_the_connector_sync_command_is_registered_with_the_scheduler_every_minute_on_one_server(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan signals:connectors:sync-scheduled/', $output);

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'signals:connectors:sync-scheduled'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping, 'a slow tick must not start a second overlapping tick');
        $this->assertTrue($event->onOneServer, 'with several app servers only one may dispatch');
    }

    public function test_sync_jobs_go_to_the_signals_queue_with_limits_matching_the_documented_worker_command(): void
    {
        $job = new SyncDataConnectorJob(1, 2, 3, null);

        $this->assertSame(config('signals.queue'), $job->queue);
        $this->assertSame('signals', config('signals.queue'), 'the documented worker command is --queue=signals');
        $this->assertSame(4, $job->tries, 'matches --tries=4');
        $this->assertSame(900, $job->timeout, 'matches --timeout=900');
        $this->assertSame([60, 300, 900], $job->backoff());
    }

    public function test_the_database_queue_will_not_hand_a_running_sync_to_a_second_worker(): void
    {
        $job = new SyncDataConnectorJob(1, 2, 3, null);

        $this->assertGreaterThan(
            $job->timeout,
            (int) config('queue.connections.database.retry_after'),
            'retry_after must exceed the job timeout or the queue re-releases a job that is still running (this reads the effective DB_QUEUE_RETRY_AFTER)',
        );
    }
}
