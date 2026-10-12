<?php

namespace Tests\Unit\Gtm;

use App\Domain\Gtm\GtmEvents;
use App\Services\Events\EventCatalogue;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Keeps three things identical: the event names the code emits, GtmEvents (GTM's metadata), and the real
 * EventCatalogue::SHIPPED. Add an event in one place and forget another, and this fails.
 */
class GtmEventsTest extends TestCase
{
    public function test_gtm_events_and_the_real_catalogue_list_exactly_the_same_gtm_events(): void
    {
        $inCatalogue = array_values(array_filter(array_keys(EventCatalogue::SHIPPED), fn ($t) => str_starts_with($t, 'gtm.')));
        sort($inCatalogue);
        $mine = GtmEvents::all();
        sort($mine);

        $this->assertSame($mine, $inCatalogue, 'GtmEvents::TYPES and EventCatalogue::SHIPPED must list the same gtm.* events');
    }

    public function test_every_gtm_event_has_a_consumer_that_does_something(): void
    {
        foreach (GtmEvents::TYPES as $type => $meta) {
            $consumers = EventCatalogue::SHIPPED[$type] ?? [];
            $this->assertArrayHasKey('AuditLogProjector', $consumers, "$type must be projected into the audit log");
            $this->assertSame($meta['triggerable'], isset($consumers['GtmWorkflowRunner']), "$type: a triggerable event is consumed by the workflow runner, and only those");
        }
    }

    public function test_the_catalogue_invariants_hold(): void
    {
        $this->assertSame([], EventCatalogue::assertInvariants());
    }

    public function test_every_gtm_event_name_in_the_code_is_registered(): void
    {
        $found = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), ['GtmEvents.php', 'EventCatalogue.php'], true)) {
                continue;
            }
            // string literals only: 'gtm.something.something'
            if (preg_match_all("/['\"](gtm\.[a-z_]+\.[a-z_]+)['\"]/", File::get($file->getPathname()), $m)) {
                foreach ($m[1] as $name) {
                    if (config($name) !== null) {
                        continue; // a config key such as gtm.import.max_rows, not an event
                    }
                    $found[$name][] = $file->getFilename();
                }
            }
        }
        $this->assertNotEmpty($found, 'the scan must actually find the emitted events');
        $unregistered = array_diff_key($found, GtmEvents::TYPES);
        $this->assertSame([], $unregistered, 'these event names appear in code but are not registered in GtmEvents: '.json_encode($unregistered));
    }

    public function test_every_registered_event_is_actually_emitted_somewhere(): void
    {
        $source = '';
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() === 'php' && ! in_array($file->getFilename(), ['GtmEvents.php', 'EventCatalogue.php'], true)) {
                $source .= File::get($file->getPathname());
            }
        }
        $dead = [];
        foreach (GtmEvents::all() as $type) {
            // a literal anywhere in code (events are never built dynamically, so they can be found by search)
            if (! str_contains($source, "'{$type}'") && ! str_contains($source, "\"{$type}\"")) {
                $dead[] = $type;
            }
        }
        $this->assertSame([], $dead, 'registered but never emitted (dead catalogue entries): '.implode(', ', $dead));
    }
}
