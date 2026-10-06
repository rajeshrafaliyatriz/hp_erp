<?php

namespace App\Http\Controllers\Api\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Services\Notifications\TerminologyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Q-F1's vocabulary endpoint.
 *
 * IT LIVES UNDER Notifications BECAUSE X-06 BUILT IT, AND THAT IS THE ONLY
 * REASON. Terminology is not a notification concern: the same map drives screen
 * labels and report headings, and the frontend is expected to read it once at
 * session start rather than per notification. If a later slice gives terminology
 * its own home, this moves - the CONTRACT is `GET /api/terminology`, not the
 * namespace it currently sits in.
 */
class TerminologyController extends Controller
{
    use ResolvesApiIdentity;

    public function __construct(private TerminologyService $terminology)
    {
    }

    /**
     * The tenant's resolved vocabulary: global defaults with their overrides
     * already applied, so the caller never has to merge two layers itself.
     */
    public function index(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $locale = $this->locale($request);
        $map    = $this->terminology->map($identity['sub_institute_id'], $locale);

        // Which keys this tenant has actually overridden. The frontend does not
        // need it; an administrator looking at the settings screen does, because
        // "this is your word" and "this is ours" look identical once merged.
        $overridden = DB::table('g2g_terminology')
            ->where('sub_institute_id', $identity['sub_institute_id'])
            ->where('locale', $locale)
            ->pluck('term_key')
            ->all();

        return response()->json([
            'status'     => 1,
            'locale'     => $locale,
            'terms'      => $map,
            'overridden' => $overridden,
        ]);
    }

    /**
     * Set or clear one tenant override.
     *
     * A tenant may only write ITS OWN row. sub_institute_id comes from the token
     * and is never read from the request - writing a global row (0) would change
     * the default word for every customer on the platform.
     */
    public function update(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);
        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = $identity['sub_institute_id'];
        $locale = $this->locale($request);

        $data = $request->validate([
            'term_key' => 'required|string|max:64',
            'singular' => 'required|string|max:191',
            'plural'   => 'required|string|max:191',
        ]);

        // A tenant renames terms the product HAS. Inventing a key would create a
        // row nothing ever reads, which looks like a working override until
        // somebody notices the screen never changed.
        if (!in_array($data['term_key'], $this->terminology->knownKeys($locale), true)) {
            return response()->json([
                'status'  => 0,
                'message' => "Unknown term '{$data['term_key']}'.",
                'known'   => $this->terminology->knownKeys($locale),
            ], 422);
        }

        DB::table('g2g_terminology')->updateOrInsert(
            ['sub_institute_id' => $tenant, 'term_key' => $data['term_key'], 'locale' => $locale],
            [
                'singular'   => $data['singular'],
                'plural'     => $data['plural'],
                'updated_by' => $identity['user_id'],
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return response()->json([
            'status' => 1,
            // A fresh service instance: the one injected here has already cached
            // the pre-write map, and returning it would show the caller their old
            // wording immediately after changing it.
            'terms'  => (new TerminologyService())->map($tenant, $locale),
        ]);
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->input('locale', TerminologyService::DEFAULT_LOCALE);

        // Only 'en' is populated. An unknown locale returning an EMPTY map would
        // render every label as its key; falling back keeps the screen readable.
        return preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)
            ? $locale
            : TerminologyService::DEFAULT_LOCALE;
    }
}
