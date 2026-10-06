<?php

namespace App\Domain\AI\Templates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The signed-in organisation's own name and logo, for a report layout's letterhead.
 *
 * G2G's equivalent of LMS_K12's `InstituteBranding`. Read from where G2G's Organization
 * Profile screen writes them (organizationDetailsController): the name from
 * `institute_detail.organization_name`, falling back to `school_setup.SchoolName`, and the
 * logo from `school_setup.Logo` on the `digitalocean` disk under `public/hp_logo/`.
 *
 * Null rather than a default when an organisation has set none — a report must never
 * carry another organisation's, or a made-up, letterhead.
 */
final class OrganisationBranding
{
    /** @return array{institute_name: string|null, logo_url: string|null, sub_institute_id: int|string|null} */
    public function for(int|string|null $institute): array
    {
        $name = null;
        $logo = null;

        try {
            $name = DB::table('institute_detail')->where('sub_institute_id', $institute)->value('organization_name');
            $setup = DB::table('school_setup')->where('id', $institute)->first(['SchoolName', 'Logo']);

            $name = trim((string) ($name ?? '')) !== '' ? trim((string) $name) : (trim((string) ($setup->SchoolName ?? '')) ?: null);

            if (trim((string) ($setup->Logo ?? '')) !== '') {
                $logo = Storage::disk('digitalocean')->url('public/hp_logo/' . trim((string) $setup->Logo));
            }
        } catch (Throwable) {
            // Branding is decoration. A lookup failure leaves the letterhead blank rather
            // than failing the options call every template screen depends on.
        }

        return ['institute_name' => $name, 'logo_url' => $logo, 'sub_institute_id' => $institute];
    }
}
