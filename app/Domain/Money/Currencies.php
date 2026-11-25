<?php

namespace App\Domain\Money;

use App\Services\Organization\TenantSettings;
use Illuminate\Support\Facades\Schema;

/**
 * The currency catalogue (config/currencies.php; S16 contract section 2) and the few questions asked of it, in ONE place: is this a catalogue code, how many decimals does it
 * keep, which symbol does it wear, what is an organisation's default.
 *
 * A code outside the catalogue is LEGACY (free text typed before the list existed). It is still readable and is shown as typed (symbol(): the code itself, decimals(): 2); it is
 * never rewritten here. Whether a NEW value may be a legacy one is the writer's rule (CatalogueFields::currencyError): only the value the row already holds.
 */
final class Currencies
{
    /** @return list<array{code: string, name: string, symbol: string, decimals: int, country: string}> the catalogue, in the order the config has it */
    public static function all(): array
    {
        return array_values(array_map(fn (array $c): array => [
            'code' => (string) $c['code'], 'name' => (string) $c['name'], 'symbol' => (string) $c['symbol'], 'decimals' => (int) $c['decimals'], 'country' => (string) $c['country'],
        ], (array) config('currencies.currencies', [])));
    }

    /** A code as the catalogue spells it (trimmed, upper case), or null for nothing. A value that is no catalogue code is still normalised: the caller decides what that means. */
    public static function normalise(mixed $code): ?string
    {
        if (! is_scalar($code)) {
            return null;
        }
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    /** @return ?array{code: string, name: string, symbol: string, decimals: int, country: string} null for a legacy value, an empty one or no value */
    public static function find(mixed $code): ?array
    {
        $code = self::normalise($code);
        if ($code === null) {
            return null;
        }
        foreach (self::all() as $currency) {
            if ($currency['code'] === $code) {
                return $currency;
            }
        }

        return null;
    }

    public static function isCode(mixed $code): bool
    {
        return self::find($code) !== null;
    }

    /** The digits a currency keeps after the point. A legacy or empty code keeps two, like the columns that hold the amounts. */
    public static function decimals(mixed $code): int
    {
        return self::find($code)['decimals'] ?? 2;
    }

    /** The symbol of a catalogue currency; for a code that is not in the catalogue the code as typed (upper case), for no code an empty string. */
    public static function symbol(mixed $code): string
    {
        return self::find($code)['symbol'] ?? (self::normalise($code) ?? '');
    }

    /** The currency a new record gets when nothing says otherwise: `org.currency` of the organisation when it is in the catalogue, else the config's fallback (INR). */
    public static function defaultFor(int $tenant): string
    {
        $fallback = (string) (self::find(config('currencies.fallback', 'INR'))['code'] ?? 'INR');
        if ($tenant <= 0) {
            return $fallback;
        }
        try {
            // A host with no settings table yet, or an organisation that has set nothing, answers the fallback; this is never worth a failed request.
            if (! Schema::hasTable('tenant_setting')) {
                return $fallback;
            }
            $own = (new TenantSettings())->get($tenant, 'org.currency');
        } catch (\Throwable) {
            return $fallback;
        }

        return self::find($own)['code'] ?? $fallback;
    }
}
