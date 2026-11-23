<?php

namespace App\Domain\ProductMaster;

use App\Domain\Portfolio\ProductOffer;

/**
 * The "often sold together" list of an offer (its `bundles_with` column) and the ONE rule for what may be in it (slice 15, P2-F for the one-record writer, P2-E2a for the
 * old Portfolio form).
 *
 * Every id must be an offer the organisation can see (ProductOffer::forTenant: its own or a shared one), and never the offer itself. Both doors judge it here:
 * ProductWriter (`about.oftenSoldTogether.N`) and PortfolioController store and update (`bundles_with.N`). Before this class the old form stored any list, so a person could
 * save ids that are no offer, which the Portfolio's own show route then silently dropped when it read them back.
 *
 * Pure: it reads offers and writes nothing. An id is matched exactly, in PHP, so sqlite and MySQL agree whatever the collation.
 */
final class OftenSoldTogether
{
    public const MESSAGE_ITSELF = 'An offer cannot be often sold together with itself.';

    public const MESSAGE_UNAVAILABLE = 'Choose offers of your own or shared ones: no offer with this ID is available to you.';

    /**
     * @param  array<int|string, mixed>  $list      the list as it was sent (positions are kept: an error names the position)
     * @param  string|null               $ownOfferId the id of the offer the list belongs to (it may not name itself); null for none
     * @return array{clean: list<string>, errors: list<array{0: int|string, 1: string}>} the list trimmed, in order, each id once; and every problem as [position, message]
     */
    public static function check(int $tenant, array $list, ?string $ownOfferId): array
    {
        $errors = [];
        $wanted = [];
        foreach ($list as $position => $raw) {
            $id = is_scalar($raw) ? trim((string) $raw) : '';
            if ($id !== '' && $ownOfferId !== null && $id === $ownOfferId) {
                $errors[] = [$position, self::MESSAGE_ITSELF];
            } else {
                $wanted[$position] = $id;
            }
        }
        $visible = $wanted === [] ? [] : array_flip(ProductOffer::query()->forTenant($tenant)->whereIn('offer_id', array_values(array_unique(array_filter($wanted, fn (string $id): bool => $id !== ''))))->pluck('offer_id')->map(fn ($id): string => (string) $id)->all());
        $clean = [];
        foreach ($wanted as $position => $id) {
            if ($id === '' || ! isset($visible[$id])) {
                $errors[] = [$position, self::MESSAGE_UNAVAILABLE];
            } elseif (! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        return ['clean' => $clean, 'errors' => $errors];
    }
}
