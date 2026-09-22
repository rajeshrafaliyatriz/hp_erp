<?php

namespace App\Http\Controllers\Api\Organization;

use App\Http\Controllers\Api\Concerns\ResolvesApiIdentity;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * THE ORGANISATION'S OWN DETAILS — on the API stack, where the frontend is.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS ALONGSIDE settings\organizationDetailsController
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * That controller is registered in routes/settings.php under
 * `['auth','session','menu']` - the WEB stack. It then reads a Sanctum token out
 * of the request body and resolves the tenant from it. So it demands a browser
 * session AND a token, and the Next.js frontend has only the token.
 *
 * The result is the audit's stage-3 `NOT-WIRED` break: Organization Profile is a
 * finished screen with a finished controller behind it, and the two cannot talk.
 * `org_details` holds 4 rows for 12 live organisations, which is what that looks
 * like from the data side.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * FOUR DEFECTS NOT CARRIED OVER
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   1. `created_by`/`updated_by` came from `$request->user_id` - caller-supplied,
 *      and FK-constrained to tbluser.id, so a wrong value is a 500 rather than a
 *      validation error. Here they come from the TOKEN.
 *   2. The 422 branch itself fatals: `$validator->messages()->first()->first()`
 *      calls ->first() on a string. Laravel's own `validate()` is used instead.
 *   3. Sister companies were read with unguarded array access - a missing
 *      `legal_name` key is an undefined-index error, not a message.
 *   4. Nothing was transactional. Sister companies are DELETED and re-inserted,
 *      so a failure mid-loop lost the ones already removed.
 *
 * The old controller is left where it is. Something may still reach it from a
 * Blade screen, and removing it is a separate decision from giving the frontend
 * a door that works.
 */
class OrganizationProfileController extends Controller
{
    use ResolvesApiIdentity;

    /** GET /api/organization/profile */
    public function show(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = (int) $identity['sub_institute_id'];

        $profile = DB::table('org_details')->where('sub_institute_id', $tenant)->first();

        $sisters = $profile
            ? DB::table('org_sister_details')->where('sub_institute_id', $tenant)->get()
            : collect();

        /*
         * ═══════════════════════════════════════════════════════════════════
         * WHO THIS ORGANISATION IS, FROM THE TABLE THAT ACTUALLY HAS A ROW
         * ═══════════════════════════════════════════════════════════════════
         *
         * Three tables describe an organisation, and they disagree about how many
         * organisations exist: `school_setup` has 12 rows on live, `org_details`
         * has 4, `institute_detail` has 5. So `org_details` cannot be the source
         * of an organisation's IDENTITY - for eight of twelve tenants it is simply
         * absent, and a screen reading the logo from it shows nothing.
         *
         * `school_setup` is the source of truth for identity, because it IS the
         * tenant: `school_setup.id` is `sub_institute_id`. `org_details` keeps what
         * it is actually for - the statutory record: legal name, CIN, GSTIN, PAN,
         * registered address.
         *
         * ── THE LOGO WAS WRITTEN TWICE AND THE COPIES DRIFTED ───────────────
         *
         * `save()` below writes `org_details.logo` and mirrors it into
         * `school_setup.Logo`. The two have since diverged on live - tenant 1 holds
         * a different filename in each - and three different organisations ended up
         * sharing one file. `identity.logo` prefers `school_setup`, so every tenant
         * has an answer, and the migration reconciles the historic divergence.
         */
        $setup = DB::table('school_setup')
            ->where('id', $tenant)
            ->first(['SchoolName', 'ShortCode', 'Logo', 'ContactPerson', 'Mobile', 'Email', 'institute_type']);

        return response()->json([
            'status' => true,
            'data' => [
                // NULL rather than an empty object. "No profile yet" and "a
                // profile with every field blank" are different states, and the
                // setup checklist distinguishes them.
                'profile' => $profile,
                'sister_companies' => $sisters,
                'organisation_name' => $setup->SchoolName ?? null,
                /*
                 * The organisation's own identity, always present because
                 * `school_setup` always has a row for a tenant that can sign in.
                 *
                 * `logo` falls back to `org_details.logo` only when `school_setup`
                 * has none - not the other way round. Preferring the statutory
                 * table would reintroduce the eight tenants with no answer.
                 */
                'identity' => [
                    'name' => $setup->SchoolName ?? null,
                    'short_code' => $setup->ShortCode ?? null,
                    'logo' => ($setup->Logo ?? null) ?: ($profile->logo ?? null),
                    'logo_url' => $this->logoUrl(($setup->Logo ?? null) ?: ($profile->logo ?? null)),
                    'contact_person' => $setup->ContactPerson ?? null,
                    'mobile' => $setup->Mobile ?? null,
                    'email' => $setup->Email ?? null,
                    'institute_type' => $setup->institute_type ?? null,
                    /*
                     * The public careers address, for anything that has to link
                     * to a job advert from inside the product - the hiring
                     * poster prints it, so it has to be readable here.
                     *
                     * NULL for a tenant with no institute_detail row, which is
                     * most of them: that table has 5 rows against school_setup's
                     * 12. A null slug means the organisation has no careers page
                     * at all, and callers must treat it as "feature unavailable"
                     * rather than building a URL that 404s.
                     */
                    'careers_slug' => DB::table('institute_detail')
                        ->where('sub_institute_id', $tenant)
                        ->whereNull('deleted_at')
                        ->value('careers_slug'),
                ],
            ],
        ]);
    }

    /** POST /api/organization/profile */
    public function save(Request $request)
    {
        $identity = $this->resolveApiIdentity($request);

        if (!is_array($identity)) {
            return $identity;
        }

        $tenant = (int) $identity['sub_institute_id'];
        $actor = (int) $identity['user_id'];

        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:191'],
            'cin' => ['nullable', 'string', 'max:191'],
            'gstin' => ['nullable', 'string', 'max:191'],
            'pan' => ['nullable', 'string', 'max:191'],
            'registered_address' => ['nullable', 'string'],
            'industry' => ['nullable', 'string', 'max:191'],
            'employee_count' => ['nullable', 'string', 'max:191'],
            'work_week' => ['nullable', 'string', 'max:191'],
            'mobile_no' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:500'],

            // Sister companies arrive whole or not at all. Declaring the shape
            // is what turns a missing key from an undefined-index error into a
            // message somebody can act on.
            'sister_companies' => ['sometimes', 'array'],
            'sister_companies.*.legal_name' => ['required', 'string', 'max:191'],
            'sister_companies.*.cin' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.gstin' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.pan' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.registered_address' => ['nullable', 'string'],
            'sister_companies.*.industry' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.employee_count' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.work_week' => ['nullable', 'string', 'max:191'],
            'sister_companies.*.mobile_no' => ['nullable', 'string', 'max:20'],
            'sister_companies.*.country_code' => ['nullable', 'string', 'max:5'],
            'sister_companies.*.email' => ['nullable', 'email', 'max:255'],
            'sister_companies.*.website' => ['nullable', 'url', 'max:500'],
        ]);

        $logo = $this->storeLogo($request, 'logo');

        DB::transaction(function () use ($data, $tenant, $actor, $logo, $request) {
            $existing = DB::table('org_details')->where('sub_institute_id', $tenant)->first(['id']);

            $columns = [
                'legal_name' => $data['legal_name'],
                'cin' => $data['cin'] ?? null,
                'gstin' => $data['gstin'] ?? null,
                'pan' => $data['pan'] ?? null,
                'registered_address' => $data['registered_address'] ?? null,
                'industry' => $data['industry'] ?? null,
                'employee_count' => $data['employee_count'] ?? null,
                'work_week' => $data['work_week'] ?? null,
                'mobile_no' => $data['mobile_no'] ?? null,
                'country_code' => $data['country_code'] ?? '+91',
                'email' => $data['email'] ?? null,
                'website' => $data['website'] ?? null,
                'updated_at' => now(),
            ];

            // A new logo replaces the old one; NO logo in the request leaves the
            // existing one alone. Writing null here would delete somebody's logo
            // every time they edited their address.
            if ($logo !== null) {
                $columns['logo'] = $logo;
            }

            if ($existing) {
                $columns['updated_by'] = $actor;
                DB::table('org_details')->where('id', $existing->id)->update($columns);
                $orgId = (int) $existing->id;
            } else {
                $columns['sub_institute_id'] = $tenant;
                $columns['created_by'] = $actor;
                $columns['updated_by'] = $actor;
                $columns['created_at'] = now();
                $orgId = (int) DB::table('org_details')->insertGetId($columns);
            }

            if ($logo !== null) {
                // The tenant's own logo field, kept in step. Note the column is
                // `id`, not `Id` as the web controller writes it - that works
                // only because MySQL folds identifier case.
                DB::table('school_setup')->where('id', $tenant)->update(['Logo' => $logo]);
            }

            if (!$request->has('sister_companies')) {
                return;
            }

            /*
             * Delete-then-reinsert, inside the transaction.
             *
             * The list IS the state - a sister company absent from the payload
             * has been removed - and there is no stable id on the client to
             * diff against. Outside a transaction this was a real hazard: a
             * failure after the delete lost every sister company the
             * organisation had.
             */
            DB::table('org_sister_details')
                ->where('org_id', $orgId)
                ->where('sub_institute_id', $tenant)
                ->delete();

            foreach ($data['sister_companies'] ?? [] as $index => $sister) {
                DB::table('org_sister_details')->insert([
                    'org_id' => $orgId,
                    'sub_institute_id' => $tenant,
                    'legal_name' => $sister['legal_name'],
                    'cin' => $sister['cin'] ?? null,
                    'gstin' => $sister['gstin'] ?? null,
                    'pan' => $sister['pan'] ?? null,
                    'registered_address' => $sister['registered_address'] ?? null,
                    'industry' => $sister['industry'] ?? null,
                    'employee_count' => $sister['employee_count'] ?? null,
                    'work_week' => $sister['work_week'] ?? null,
                    'mobile_no' => $sister['mobile_no'] ?? null,
                    'country_code' => $sister['country_code'] ?? '+91',
                    'email' => $sister['email'] ?? null,
                    'website' => $sister['website'] ?? null,
                    'logo' => $this->storeLogo($request, "sister_companies.$index.logo"),
                    'created_by' => $actor,
                    'updated_by' => $actor,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'status' => true,
            'message' => 'Your organisation details are saved.',
        ]);
    }

    /**
     * Put an uploaded logo on the object store and return its filename.
     *
     * Returns null when there is no file, which every caller reads as "leave
     * whatever is there alone" rather than "clear it".
     */
    /**
     * A servable URL for a stored logo filename, or null.
     *
     * Mirrors `AccountController::avatarUrl` deliberately - same disk, same
     * try/catch, different folder (`hp_logo/` for organisations, `hp_user/` for
     * people, and they have never crossed). A misconfigured disk must not be able
     * to 500 the organisation screen for a cosmetic field, so a failure degrades to
     * null and the screen falls back to the monogram.
     */
    private function logoUrl(?string $filename): ?string
    {
        $filename = trim((string) $filename);

        if ($filename === '') {
            return null;
        }

        try {
            return Storage::disk('digitalocean')->url('public/hp_logo/' . $filename);
        } catch (\Throwable) {
            return null;
        }
    }

    private function storeLogo(Request $request, string $field): ?string
    {
        if (!$request->hasFile($field)) {
            return null;
        }

        $file = $request->file($field);
        $name = time() . '_' . $file->getClientOriginalName();

        Storage::disk('digitalocean')->putFileAs('public/hp_logo/', $file, $name, 'public');

        return $name;
    }
}
