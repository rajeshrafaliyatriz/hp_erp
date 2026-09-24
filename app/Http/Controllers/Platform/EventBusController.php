<?php

namespace App\Http\Controllers\Platform;

use App\Services\Platform\EventBusReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The Event Bus console's reads.
 *
 * Six GETs and no writes, and that is a design rather than an omission — see
 * `EventBusReader`'s note on why a replay button is not a feature.
 *
 * Every method takes the tenant from `scope()`, which `AiContextHydrator` resolved from
 * the caller's token. None of them reads an organisation from request input, so there is
 * no parameter to tamper with.
 */
class EventBusController extends PlatformController
{
    public function __construct(private readonly EventBusReader $reader)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            return $this->success(
                'Event bus summary.',
                $this->reader->summary($this->scope($request)->selectedInstituteId)
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The event stream.
     *
     * The filter is `event_type`, not `type` — `type=API` is the transport marker every
     * frontend call already sends, so a filter named `type` would arrive occupied.
     */
    public function stream(Request $request): JsonResponse
    {
        try {
            $page = $this->page($request);
            $limit = $this->limit($request, 25, 200);

            $result = $this->reader->stream(
                $this->scope($request)->selectedInstituteId,
                [
                    'event_type' => $this->text($request, 'event_type'),
                    'entity_type' => $this->text($request, 'entity_type'),
                    'from' => $this->date($request, 'from'),
                    'to' => $this->date($request, 'to', true),
                ],
                $page,
                $limit
            );

            return $this->success(
                'Event stream.',
                $this->paged($result['rows'], $result['total'], $page, $limit)
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function consumers(Request $request): JsonResponse
    {
        try {
            return $this->success(
                'Consumers.',
                ['rows' => $this->reader->consumers($this->scope($request)->selectedInstituteId)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    public function failures(Request $request): JsonResponse
    {
        try {
            $page = $this->page($request);
            $limit = $this->limit($request, 25, 200);

            $result = $this->reader->failures(
                $this->scope($request)->selectedInstituteId,
                $page,
                $limit
            );

            return $this->success(
                'Failed deliveries.',
                $this->paged($result['rows'], $result['total'], $page, $limit)
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /**
     * The catalogue. Tenant-independent — it describes the product, not the data — but
     * still behind the same gate, because it names internal class structure.
     */
    public function catalogue(Request $request): JsonResponse
    {
        try {
            $this->scope($request);

            return $this->success('Event catalogue.', $this->reader->catalogue());
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    /** Filter options, read from what this tenant has actually recorded. */
    public function options(Request $request): JsonResponse
    {
        try {
            return $this->success(
                'Filter options.',
                ['event_types' => $this->reader->eventTypes($this->scope($request)->selectedInstituteId)]
            );
        } catch (Throwable $exception) {
            return $this->handle($exception);
        }
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim((string) $request->input($key, ''));

        return $value === '' ? null : $value;
    }

    /**
     * A date filter, as a whole day.
     *
     * An unparseable value is IGNORED rather than rejected. A filter is a convenience,
     * and a malformed one should widen the view rather than 500 the page somebody was
     * using to diagnose something else.
     */
    private function date(Request $request, string $key, bool $endOfDay = false): ?string
    {
        $value = $this->text($request, $key);

        if ($value === null) {
            return null;
        }

        try {
            $date = \Illuminate\Support\Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        return ($endOfDay ? $date->endOfDay() : $date->startOfDay())->toDateTimeString();
    }
}
