<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * GRANT, REVOKE AND LIST THE PEOPLE WHO MAY CREATE ORGANISATIONS.
 *
 *   php artisan platform:owner list
 *   php artisan platform:owner grant  --email=someone@example.com --by=28
 *   php artisan platform:owner revoke --email=someone@example.com --by=28
 *
 * Add --database=live to act on the live database.
 *
 * ── WHY A COMMAND AND NOT A SCREEN ──────────────────────────────────────────
 *
 * This is the one permission in the product that is not a customer's to manage,
 * and there are two of these people, not two hundred. A screen would be a screen
 * whose own access has to be gated by the thing it grants - and the first grant
 * would still have to come from somewhere else.
 *
 * It also keeps the audit honest: `granted_by` is a person passed on the command
 * line by whoever ran it, not a session that might be a shared login.
 */
class PlatformOwnerCommand extends Command
{
    protected $signature = 'platform:owner
                            {action : list, grant or revoke}
                            {--email= : The account to grant or revoke}
                            {--by= : The user id of the person making the change}
                            {--note= : Why, for the record}
                            {--database= : Connection to act on (default: the app default)}';

    protected $description = 'Manage who is allowed to create organisations';

    public function handle(): int
    {
        $connection = $this->option('database');

        if ($connection) {
            DB::setDefaultConnection($connection);
        }

        $this->line('database: ' . DB::getDefaultConnection());

        return match ($this->argument('action')) {
            'list' => $this->listOwners(),
            'grant' => $this->grant(),
            'revoke' => $this->revoke(),
            default => $this->fail('Unknown action. Use list, grant or revoke.'),
        };
    }

    private function listOwners(): int
    {
        $rows = DB::table('platform_owners as p')
            ->leftJoin('tbluser as u', 'u.id', '=', 'p.user_id')
            ->orderBy('p.id')
            ->get(['p.user_id', 'u.email', 'u.first_name', 'p.granted_at', 'p.revoked_at', 'p.note']);

        if ($rows->isEmpty()) {
            $this->warn('Nobody can create an organisation. Grant somebody first.');
            return self::SUCCESS;
        }

        $this->table(
            ['user', 'email', 'name', 'granted', 'revoked', 'note'],
            $rows->map(fn ($r) => [
                $r->user_id,
                // A row whose user is gone reads as missing rather than blank -
                // the middleware refuses it either way, and this says why.
                $r->email ?? '(user no longer exists)',
                $r->first_name ?? '-',
                $r->granted_at ?? '-',
                $r->revoked_at ?? 'active',
                $r->note ?? '-',
            ])->all()
        );

        return self::SUCCESS;
    }

    private function grant(): int
    {
        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        $existing = DB::table('platform_owners')->where('user_id', $user->id)->first();

        if ($existing && $existing->revoked_at === null) {
            $this->info(sprintf('%s (#%d) already creates organisations. Nothing to do.', $user->email, $user->id));
            return self::SUCCESS;
        }

        $payload = [
            'granted_by' => $this->grantedBy(),
            'granted_at' => now(),
            // Clearing the revocation rather than leaving it is what makes
            // re-granting work; the row is the current state, the note carries
            // the history a human wants.
            'revoked_by' => null,
            'revoked_at' => null,
            'note' => $this->option('note'),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('platform_owners')->where('id', $existing->id)->update($payload);
            $this->info(sprintf('Re-granted: %s (#%d) can create organisations again.', $user->email, $user->id));

            return self::SUCCESS;
        }

        DB::table('platform_owners')->insert($payload + [
            'user_id' => (int) $user->id,
            'created_at' => now(),
        ]);

        $this->info(sprintf('Granted: %s (#%d) can now create organisations.', $user->email, $user->id));

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $user = $this->resolveUser();

        if (!$user) {
            return self::FAILURE;
        }

        $updated = DB::table('platform_owners')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_by' => $this->grantedBy(),
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            $this->warn(sprintf('%s (#%d) was not a platform owner. Nothing changed.', $user->email, $user->id));
            return self::SUCCESS;
        }

        // The row stays. "Who used to be able to do this, and when did it stop"
        // is a question somebody asks after the fact, and a deleted row cannot
        // answer it.
        $this->info(sprintf('Revoked: %s (#%d) can no longer create organisations.', $user->email, $user->id));

        $remaining = DB::table('platform_owners')->whereNull('revoked_at')->count();

        if ($remaining === 0) {
            $this->warn('That was the last one. Nobody can create an organisation now.');
        }

        return self::SUCCESS;
    }

    /** The account named by --email, or null with the reason printed. */
    private function resolveUser(): ?object
    {
        $email = trim((string) $this->option('email'));

        if ($email === '') {
            $this->error('--email is required for grant and revoke.');
            return null;
        }

        $user = DB::table('tbluser')
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->first(['id', 'email', 'first_name', 'sub_institute_id']);

        if (!$user) {
            $this->error(sprintf('No account with the email %s on this database.', $email));
            return null;
        }

        return $user;
    }

    /**
     * Who made the change. Null when not given rather than guessed - an audit
     * column filled with the wrong person is worse than one left empty.
     */
    private function grantedBy(): ?int
    {
        $by = $this->option('by');

        return $by !== null && $by !== '' ? (int) $by : null;
    }
}
