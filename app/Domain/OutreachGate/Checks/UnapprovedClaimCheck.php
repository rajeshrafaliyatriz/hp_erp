<?php

namespace App\Domain\OutreachGate\Checks;

use App\Domain\OutreachGate\Check;
use App\Domain\OutreachGate\CheckResult;
use App\Domain\OutreachGate\ClaimBook;
use App\Domain\OutreachGate\Draft;
use App\Domain\OutreachGate\Text;

/**
 * A sentence that makes a numeric, percentage, guarantee or superlative claim may be sent only if it appears, normalised, in an APPROVED claim of a product the draft is
 * about. No approved claim means no such sentence passes (the safe default); a draft that states a claim but names no product has nothing to rest it on, so it fails too.
 *
 * "Is about": the product the message points at (its product_master_id) plus every product of the organisation (its own and the shared ones) whose name the text
 * mentions. "Appears, normalised": the sentence, lower-cased with punctuation and spacing folded, equals the approved claim, or is a run of at least three of its words
 * (so "Cuts paperwork by 60%" is covered by "ScholarClone cuts paperwork by 60% for schools", and a bare "60%" is not).
 */
final class UnapprovedClaimCheck implements Check
{
    public const MIN_WORDS_FOR_CONTAINMENT = 3;

    /** @var list<string> compiled regular expressions */
    private array $markers;

    /** @param list<string> $markerPatterns config('outreach_gate.claim_markers') */
    public function __construct(private readonly ClaimBook $book, array $markerPatterns)
    {
        $this->markers = array_map(fn (string $p) => Text::regex($p), $markerPatterns);
    }

    public function key(): string
    {
        return 'unapproved_claim';
    }

    public function label(): string
    {
        return 'Only approved claims';
    }

    /** @return list<string> the sentences (subject and body) that make a claim, in order */
    public function claimSentences(Draft $draft): array
    {
        $out = [];
        foreach ([...Text::sentences($draft->subject), ...Text::sentences($draft->body)] as $sentence) {
            foreach ($this->markers as $regex) {
                if (preg_match($regex, $sentence) === 1) {
                    $out[] = $sentence;
                    break;
                }
            }
        }

        return $out;
    }

    /** Whether a sentence is covered by one of the approved claims (both already normalised). */
    public static function covered(string $normalisedSentence, array $normalisedClaims): bool
    {
        if ($normalisedSentence === '') {
            return true;
        }
        foreach ($normalisedClaims as $claim) {
            if ($normalisedSentence === $claim) {
                return true;
            }
            if (Text::words($normalisedSentence) >= self::MIN_WORDS_FOR_CONTAINMENT && str_contains(' ' . $claim . ' ', ' ' . $normalisedSentence . ' ')) {
                return true;
            }
        }

        return false;
    }

    public function run(Draft $draft): CheckResult
    {
        $claims = $this->claimSentences($draft);
        if ($claims === []) {
            return CheckResult::pass($this->key(), $this->label(), 'The text states no numeric, percentage, guarantee or superlative claim.');
        }

        $products = array_values(array_unique([
            ...$this->book->visibleProductIds($draft->tenant, $draft->productIds),
            ...$this->book->productsNamedIn($draft->tenant, $draft->text()),
        ]));
        if ($products === []) {
            return CheckResult::fail($this->key(), $this->label(), 'States a claim but names no product, so there is no approved claim to rest it on: ' . Text::quote($claims[0]) . (count($claims) > 1 ? ' and ' . (count($claims) - 1) . ' more' : '') . '.');
        }

        $approved = $this->book->approved($draft->tenant, $products);
        $unapproved = array_values(array_filter($claims, fn (string $s) => ! self::covered(Text::normalise($s), $approved)));
        if ($unapproved === []) {
            return CheckResult::pass($this->key(), $this->label(), count($claims) . ' claim sentence' . (count($claims) === 1 ? '' : 's') . ', all approved for the product.');
        }

        return CheckResult::fail(
            $this->key(),
            $this->label(),
            ($approved === [] ? 'No claim is approved for the product yet. ' : '') . 'Not an approved claim: ' . implode('; ', array_map(fn (string $s) => Text::quote($s, 70), array_slice($unapproved, 0, 2))) . (count($unapproved) > 2 ? ' and ' . (count($unapproved) - 2) . ' more' : '') . '.'
        );
    }
}
