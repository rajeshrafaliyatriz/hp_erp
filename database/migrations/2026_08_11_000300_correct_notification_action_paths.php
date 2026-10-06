<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G-NOTIF-02 — I SHIPPED SIX NOTIFICATIONS WHOSE "ACT ON IT" LINK 404s.
 *
 * X-06 deferred `certification.issued` partly because "its action link would
 * point at a certificate screen that has not been built". THAT REASON APPLIED TO
 * ALL SIX EVENTS I DID SHIP, and I did not check one of them.
 *
 * Every path was invented from the shape of the domain rather than read from the
 * router:
 *
 *   /tasks/{id}                          404
 *   /competency/my-capability            404   <- there is no /competency route
 *   /competency/development-plan/{id}    404
 *   /talent/offboarding/{id}             404
 *   /settings/my-access                  404   <- /settings exists, that child does not
 *
 * WHY THEY CANNOT SIMPLY BE FIXED: the competency, task and talent screens are
 * reached through `/module/[moduleId]/[menuId]/[submenuId]`, and those ids come
 * from `tblmenumaster_g2g` AT RUNTIME, per tenant. THERE IS NO STATIC PATH TO
 * HARDCODE. A correct deep link needs a resolver that turns "the capability
 * screen" into this tenant's menu ids, and that does not exist yet.
 *
 * SO: NULL, EXCEPT WHERE A REAL STATIC ROUTE EXISTS.
 *   NULL means the bell renders the message with no link - which the frontend
 *   already handles - instead of sending someone to a dead page. A message that
 *   says what happened is worth having; a link that breaks is not.
 *
 *   `development_plan.approved` keeps a link, because `/lms/training-records/
 *   assignment` IS a real route and IS where X-12 writes its assignments. It is
 *   the one case where the destination genuinely exists.
 *
 * VERIFIED against g2gv0's app router on 2026-08-11 by listing every page.tsx.
 */
return new class extends Migration
{
    /** Real static routes, listed from g2gv0/app/**\/page.tsx on 2026-08-11. */
    public const VERIFIED_ROUTES = [
        '/dashboard', '/dashboard/admin', '/dashboard/attendance',
        '/dashboard/attendance-report', '/dashboard/hr-operations',
        '/dashboard/leave-management', '/dashboard/personal', '/dashboard/team',
        '/lms/learning/dashboard', '/lms/training-records/assignment',
        '/organization', '/organization/departments', '/organization/employees',
        '/organization/hierarchy', '/organization/information',
        '/profile', '/settings', '/settings/module-configuration',
    ];

    public function up(): void
    {
        // Everything to NULL first - the safe state - then restore the one link
        // that resolves. Doing it in this order means a path I have not verified
        // cannot survive by being missed.
        DB::table('g2g_notification_template')->update(['action_path' => null]);

        DB::table('g2g_notification_template')
            ->where('event_type', 'development_plan.approved')
            ->update(['action_path' => '/lms/training-records/assignment']);

        // certification.issued: seeded here because X-11 un-defers it.
        foreach (['inapp', 'email'] as $channel) {
            DB::table('g2g_notification_template')->updateOrInsert(
                ['event_type' => 'certification.issued', 'channel' => $channel, 'locale' => 'en'],
                [
                    'subject' => 'Your {term:certification} has been issued',
                    'body'    => "You completed {payload.certificate_name} and your {term:certification} has been issued.\n\nCertificate number: {payload.certificate_number}\n\nIt is on your training record.",
                    // NULL for the same reason as the rest: there is no
                    // certificate screen, which is what deferred it in the first
                    // place. The notification is still worth sending - the
                    // certificate number is IN the message.
                    'action_path' => null,
                    'is_active'   => true,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // no-op by design. Restoring broken links is not a rollback worth having.
    }
};
