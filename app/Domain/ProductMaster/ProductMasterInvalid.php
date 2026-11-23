<?php

namespace App\Domain\ProductMaster;

/**
 * A Product Master write that cannot be done, with every reason by field. Nothing has been written when it is thrown: the writes run in one
 * transaction. `status` is the HTTP status the API answers with: 422 invalid input, 409 a clash with an existing record, 403 not allowed,
 * 404 an unknown or other-organisation record.
 */
final class ProductMasterInvalid extends \RuntimeException
{
    /**
     * @param array<string, list<string>> $errors field => messages
     * @param list<string> $problems the rule problems as CustomProductRules worded them ("key: ...", "label: ..."), in the order it found them; only a refusal that comes from
     *                               those rules carries them (ProductLines::create), so a caller that has always answered with that list (the custom product API) can keep doing so
     */
    public function __construct(public readonly array $errors, public readonly int $status = 422, string $message = '', public readonly array $problems = [])
    {
        $first = $errors === [] ? '' : (string) (reset($errors)[0] ?? '');
        parent::__construct($message !== '' ? $message : ($first !== '' ? $first : 'The product could not be saved'));
    }

    public static function field(string $field, string $message, int $status = 422): self
    {
        return new self([$field => [$message]], $status);
    }
}
