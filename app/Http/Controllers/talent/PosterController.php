<?php

namespace App\Http\Controllers\talent;

use App\Http\Controllers\Controller;
use App\Http\Controllers\talent\Concerns\ResolvesPublicCareers;
use App\Services\Talent\PosterContentService;
use App\Support\CandidateLink;
use App\Support\Poster\PosterFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * The hiring poster: content for the image renderer, and the A4 PDF.
 *
 * ── WHY THIS IS ON THE PUBLIC CAREERS SURFACE ───────────────────────────────
 *
 * A poster advertises a job that is already public. Everything it prints comes
 * from the same allow-list the careers page uses, so this endpoint exposes
 * strictly less than GET /careers/{slug}/postings/{id} already does.
 *
 * Putting it here rather than behind the admin token avoids the one thing that
 * would actually be dangerous: the Sanctum token lives in localStorage and is
 * unreadable by Next.js server code, so an authenticated poster route would
 * have to carry the token in a URL - and next.config.mjs logs full fetch URLs.
 *
 * ── WHY AN UNPUBLISHED ROLE GETS NO POSTER ──────────────────────────────────
 *
 * The only actionable thing on a poster is the Apply link. For a draft or a
 * closed role that link leads to "This role is not available", so producing the
 * file is worse than refusing: the poster would be shared before anyone opened
 * it. A 404 here is the feature working.
 */
class PosterController extends Controller
{
    use ResolvesPublicCareers;

    /** More than three roles on one poster is unreadable at 1080px wide. */
    private const MAX_ROLES = 3;

    public function __construct(private PosterContentService $content)
    {
    }

    /** GET /api/careers/{slug}/poster-content?ids=1,2&format=portrait */
    public function content(Request $request, string $slug)
    {
        $format = (string) $request->query('format', PosterFormat::PORTRAIT);

        if (!PosterFormat::exists($format)) {
            return response()->json([
                'status' => 0,
                'message' => 'Unknown poster format. Expected one of: ' . implode(', ', PosterFormat::all()) . '.',
            ], 422);
        }

        $resolved = $this->resolve($slug, $request);

        if (!is_array($resolved)) {
            return $resolved;
        }

        [$org, $postings, $dropped] = $resolved;

        return response()->json([
            'status' => 1,
            'data' => $this->content->build($org, $postings, $format),
            /*
             * Roles that closed between the moment HR ticked them and the
             * moment they pressed download. Reported rather than silently
             * omitted - a poster with two roles when three were chosen is
             * correct, but only if the person is told which one is missing.
             */
            'dropped' => $dropped,
        ]);
    }

    /** GET /api/careers/{slug}/poster.pdf?ids=1,2 */
    public function pdf(Request $request, string $slug)
    {
        $resolved = $this->resolve($slug, $request);

        if (!is_array($resolved)) {
            return $resolved;
        }

        [$org, $postings, $dropped] = $resolved;

        $content = $this->content->build($org, $postings, PosterFormat::A4);

        $pdf = Pdf::loadView('talent.poster-a4', ['poster' => $content])
            ->setPaper('a4', 'portrait');

        /*
         * The ESO export pattern (EsoController::export). dompdf can emit a
         * notice or a stray byte before the header; anything before %PDF makes
         * the file unopenable, and the reader has no way to know why.
         */
        $bytes = $pdf->output();
        $start = strpos($bytes, '%PDF');

        if ($start === false) {
            return response()->json([
                'status' => 0,
                'message' => 'The poster PDF could not be produced.',
            ], 500);
        }

        if ($start > 0) {
            $bytes = substr($bytes, $start);
        }

        $filename = $this->filename($postings, 'pdf');

        return response($bytes, 200, array_filter([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($bytes),
            // Read by the client so it can say which role did not make it.
            'X-Poster-Dropped' => $dropped ? (string) count($dropped) : null,
        ]));
    }

    /**
     * Organisation, postings and whatever was asked for but is not public.
     *
     * @return array{0:object,1:array,2:array}|\Illuminate\Http\JsonResponse
     */
    private function resolve(string $slug, Request $request)
    {
        $org = $this->resolveOrganisation($slug);

        if (!$org) {
            return response()->json(['status' => 0, 'message' => 'Careers page not found.'], 404);
        }

        /*
         * An unset FRONTEND_URL means every apply link on the poster points at
         * the API host and 404s. A poster cannot be recalled once it is shared,
         * so this refuses rather than printing a dead link.
         */
        if (CandidateLink::pointsAtApi()) {
            return response()->json([
                'status' => 0,
                'message' => 'The careers site address is not configured, so the Apply link on the poster would not work.',
            ], 503);
        }

        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->map(static fn ($id) => (int) trim($id))
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json(['status' => 0, 'message' => 'No role was chosen for the poster.'], 422);
        }

        // Truncated rather than refused: a 422 on a download button is hostile,
        // and the first three are what the person picked first.
        $wanted = $ids->take(self::MAX_ROLES);

        $rows = $this->openPostings((int) $org->sub_institute_id)
            ->whereIn('p.id', $wanted->all())
            ->get();

        $found = $rows->keyBy('id');

        // Author's selection order, not database order.
        $postings = $wanted
            ->filter(static fn ($id) => $found->has($id))
            ->map(fn ($id) => $this->presentPosting($found->get($id), true))
            ->values()
            ->all();

        if ($postings === []) {
            return response()->json([
                'status' => 0,
                'message' => 'That role is not published yet. A poster carries an Apply link, and it has to work.',
            ], 404);
        }

        $dropped = $wanted
            ->reject(static fn ($id) => $found->has($id))
            ->map(static fn ($id) => ['id' => $id, 'reason' => 'not published'])
            ->values()
            ->all();

        return [$org, $postings, $dropped];
    }

    private function filename(array $postings, string $extension): string
    {
        $base = count($postings) === 1
            ? \Illuminate\Support\Str::slug((string) $postings[0]['title'])
            : 'open-roles';

        return 'hiring-' . ($base !== '' ? $base : 'poster') . '.' . $extension;
    }
}
