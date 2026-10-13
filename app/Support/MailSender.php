<?php

namespace App\Support;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves which SMTP account a send should actually go out through, for
 * one tenant and one module - the centralized, module-wise email sending
 * feature. Sibling to `App\Support\MailGate` (which answers "may this send
 * happen at all"); this answers "through which account", a separate
 * question kept in a separate class on purpose - every real call site keeps
 * its own explicit `MailGate::allowedForTenant()` check before calling this.
 *
 * RESOLUTION ORDER
 *
 *   1. A profile row for (tenant, the given module).
 *   2. That tenant's own 'default' profile, if the module-specific one
 *      doesn't exist.
 *   3. null - the caller falls back to the plain `Mail` facade (today's one
 *      shared platform `.env` account), so a tenant who has configured
 *      nothing keeps working exactly as before this feature existed.
 *
 * WHY `Mail::build()` AND NOT `Mail::purge()` + `config()`
 *
 * `Mail::build($config)` (Illuminate\Mail\MailManager::build()) constructs a
 * one-off Mailer straight from the array given - no named-mailer
 * registration, no mutation of the shared `config()` repository, no cache to
 * bust. The purge+config-mutate alternative works under classic PHP-FPM but
 * writes to one shared config key, a real hazard under Octane/queue workers
 * processing different tenants back-to-back in the same process. Confirmed
 * directly against this app's real Laravel 12 `MailManager` source before
 * choosing this.
 *
 * WHY `alwaysFrom()` IS CALLED EXPLICITLY
 *
 * `MailManager::build()` does not read a "from" out of the config array it
 * is given - it only builds the transport. Without calling `alwaysFrom()`
 * ourselves, a message sent through a built Mailer would fall back to
 * Laravel's own global `mail.from` config, defeating the entire point of a
 * per-module sender (confirmed by reading `Mailer::__construct()`/
 * `alwaysFrom()` directly - `$from` has no other source).
 */
final class MailSender
{
    /**
     * A ready-to-use Mailer for this tenant's own module-specific profile,
     * falling back to its 'default' profile, falling back to the platform's
     * global Mail facade if the tenant has configured neither.
     */
    public static function mailer(?int $tenantId, string $moduleKey = MailSenderModules::DEFAULT): Mailer
    {
        // A null tenant (the handful of genuinely tenant-less send sites -
        // see e.g. InviteService::issue(), whose own $tenantId parameter is
        // nullable) has no profile to resolve by definition - same outcome
        // as a real tenant who simply hasn't configured one.
        $row = $tenantId === null ? null : self::resolveRow($tenantId, $moduleKey);

        return $row === null ? Mail::mailer() : self::mailerForRow($row);
    }

    /**
     * Builds a Mailer from one specific `smtp_details` row, bypassing the
     * tenant/module resolution - used by the Email & SMS settings screen's
     * own "Test" button, which must prove THIS exact row's credentials
     * work, not whichever one resolution would otherwise pick.
     */
    public static function mailerForRow(object $row): Mailer
    {
        $mailer = Mail::build([
            'transport' => 'smtp',
            'host' => $row->server_address,
            'port' => (int) $row->port,
            'username' => $row->gmail,
            'password' => Crypt::decryptString($row->password),
        ]);

        $mailer->alwaysFrom($row->gmail, $row->from_name ?: null);

        return $mailer;
    }

    /** Whether this tenant has configured ANY profile (module-specific or default) - informational, not a gate. */
    public static function hasAnyProfile(int $tenantId): bool
    {
        return DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->whereNull('deleted_at')
            ->exists();
    }

    /** @return object|null The raw smtp_details row that would be used for this (tenant, module), or null if none configured. */
    public static function resolveRow(int $tenantId, string $moduleKey): ?object
    {
        $row = DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->whereNull('deleted_at')
            ->first();

        if ($row !== null) {
            return $row;
        }

        if ($moduleKey === MailSenderModules::DEFAULT) {
            return null;
        }

        return DB::table('smtp_details')
            ->where('sub_institute_id', $tenantId)
            ->where('module_key', MailSenderModules::DEFAULT)
            ->whereNull('deleted_at')
            ->first();
    }
}
