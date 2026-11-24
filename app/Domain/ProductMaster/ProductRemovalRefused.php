<?php

namespace App\Domain\ProductMaster;

/**
 * A delete or a move of Products and Sub-products that cannot be done, and the answer the screen shows for it (S15 follow-up, 24 Nov 2026; contract section 4 of
 * Docs/agentic-crm/slices/S15-ISOLATION-DELETE-MOVE-PICKER-CONTRACT.md). Nothing has been written when it is thrown: the writes run in one transaction.
 *
 * `status` is the HTTP code (403 not allowed, 404 not one of this organisation's, 409 in use, 422 the request is not valid), `code` a short word a client can branch on
 * (`blocked`, `choose_sub_products`, `invalid_target`, `nothing_to_move`, ...) and `data` what a screen needs to explain it (the blockers, the Sub-products).
 */
final class ProductRemovalRefused extends \RuntimeException
{
    /** @param array<string, mixed>|null $data */
    public function __construct(public readonly int $status, public readonly string $reason, string $message, public readonly ?array $data = null)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Product not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function invalid(string $reason, string $message): self
    {
        return new self(422, $reason, $message);
    }

    /**
     * Something is in use: 409 `blocked`, with what blocks it and the Sub-products under it, so the dialog can say so in plain sentences and offer Archive or Move.
     *
     * @param list<array{code: string, count: int, message: string}> $blockedBy
     * @param list<array<string, mixed>> $subProducts
     */
    public static function blocked(string $message, array $blockedBy, array $subProducts = []): self
    {
        return new self(409, 'blocked', $message, ['blockedBy' => $blockedBy, 'subProducts' => $subProducts]);
    }

    /** The JSON body of the refusal: the envelope every Product Master answer has, plus the code and the data when there are any. @return array<string, mixed> */
    public function body(): array
    {
        $body = ['status' => 0, 'code' => $this->reason, 'message' => $this->getMessage()];
        if ($this->data !== null) {
            $body['data'] = $this->data;
        }

        return $body;
    }
}
