<?php

namespace App\Domain\Numbering;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hands out numbers without ever handing out the same one twice (slice 6 of the agentic CRM, defect D8; table g2g_number_sequences, one row per key).
 *
 * The number a lead used to get was "the highest number in the table plus one": read, then write, with nothing between the two, so two leads made at the
 * same moment both read 41. Here the claim is ONE statement on the counter's own row, so there is nothing to interleave:
 *
 *   MySQL / MariaDB   UPDATE ... SET next_value = LAST_INSERT_ID(next_value) + 1 WHERE seq_key = ?. The row is locked by that statement, the old value
 *                     comes back through LAST_INSERT_ID(), which belongs to this connection alone.
 *   other drivers     (the sqlite test database) the same increment, then a read of the row, inside one transaction that starts with the write.
 *
 * A key that has no row yet is SEEDED from the caller's own question ("what is the first number?", for leads: the highest number in use plus one). The
 * insert is INSERT IGNORE, so when two requests seed the same key at the same moment one wins and the other simply claims from the row it made.
 *
 * Claimed inside a transaction, the number goes back with a rollback (the row lock is held until the end, so a second request waits instead of racing).
 * Claimed on its own, a number whose record then failed to save is skipped: a sequence has gaps, never duplicates.
 *
 * Keys are `<name>:<scope>`; two keys never touch each other's row.
 */
final class NumberSequence
{
    public const TABLE = 'g2g_number_sequences';

    /** Whether this host has the table (the code can reach a server before the migration does). */
    public static function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * The next number of a key.
     *
     * @param  Closure(): int  $firstNumber  what the first claim of a key that has no row yet receives; read only then, and never below 1
     */
    public static function claim(string $key, Closure $firstNumber): int
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $claimed = self::bump($key);
            if ($claimed !== null) {
                return $claimed;
            }
            // No row yet. If another request makes it between this line and the claim, the INSERT IGNORE changes nothing and the loop claims from theirs.
            DB::table(self::TABLE)->insertOrIgnore(['seq_key' => $key, 'next_value' => max(1, $firstNumber()), 'updated_at' => now()]);
        }

        throw new \RuntimeException("The number sequence '{$key}' could not be claimed.");
    }

    /** Makes sure the NEXT claim of a key is at least $floor. It never lowers a counter. A key with no row yet is left for claim() to seed. */
    public static function raiseTo(string $key, int $floor): void
    {
        DB::table(self::TABLE)->where('seq_key', $key)->where('next_value', '<', $floor)->update(['next_value' => $floor, 'updated_at' => now()]);
    }

    /** @return int|null the number claimed, or null when the key has no row yet */
    private static function bump(string $key): ?int
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $changed = DB::update('UPDATE `' . self::TABLE . '` SET `next_value` = LAST_INSERT_ID(`next_value`) + 1, `updated_at` = ? WHERE `seq_key` = ?', [now(), $key]);
            if ($changed === 0) {
                return null;
            }

            // The write connection: LAST_INSERT_ID() is per connection, and a read replica is not the one that just ran the UPDATE.
            return (int) DB::connection()->selectOne('SELECT LAST_INSERT_ID() AS n', [], false)->n;
        }

        return DB::transaction(function () use ($key) {
            $changed = DB::table(self::TABLE)->where('seq_key', $key)->update(['next_value' => DB::raw('next_value + 1'), 'updated_at' => now()]);
            if ($changed === 0) {
                return null;
            }

            return (int) DB::table(self::TABLE)->where('seq_key', $key)->value('next_value') - 1;
        });
    }
}
