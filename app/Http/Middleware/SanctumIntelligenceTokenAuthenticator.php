<?php

namespace App\Http\Middleware;

use App\Models\auth\tbluserModel;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * THE PRODUCTION AUTHENTICATOR: a pure lookup of an EXISTING Sanctum token by its hash. It never creates, heals, binds or "provisions" anything.
 *
 * History: when this was written, App\Support\PersonalAccessTokenResolver::findToken (what RequireApiToken uses) PROVISIONED a token for any unknown string
 * (security issue A-1). That resolver has since been fixed and is strict again, but this class keeps its own direct Sanctum lookup (its routes are
 * deliberately outside the `api` middleware group; see routes/intelligence.php) and so must carry the same refusals itself: a row named like the removed
 * provisioning code's rows ('Session Token', see PersonalAccessTokenResolver::PROVISIONED_TOKEN_NAME) is refused here too, because rows the old code created
 * may still be in the database. Expiry and owner activity are enforced by IntelligenceIdentity.
 */
final class SanctumIntelligenceTokenAuthenticator implements IntelligenceTokenAuthenticator
{
    public function find(string $plainToken): ?object
    {
        try {
            $token = PersonalAccessToken::findToken($plainToken);

            if ($token === null) {
                return null;
            }

            // A row the removed provisioning code fabricated (any case, padded) is never a credential, whatever its secret.
            if (strtolower(trim((string) $token->name)) === strtolower(\App\Support\PersonalAccessTokenResolver::PROVISIONED_TOKEN_NAME)) {
                return null;
            }

            // The owner must exist AND be the application's user model. A token whose tokenable_type is any other model (or a class that does not exist, which
            // throws above) is not an Intelligence credential: a plain "invalid", never a caller built from some other table's row.
            return $token->tokenable instanceof tbluserModel ? $token : null;
        } catch (Throwable) {
            return null;
        }
    }
}
