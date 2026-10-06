<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The model hit `max_tokens` before it finished — the answer is cut off.
 *
 * ── WHY THIS IS ITS OWN EXCEPTION ───────────────────────────────────────────
 *
 * A truncated JSON answer fails `json_decode` and, before this existed, arrived
 * at the caller as the same generic "could not be parsed" error a malformed
 * answer produces. Those two are not the same problem and do not have the same
 * fix: malformed means re-ask, truncated means ASK FOR LESS OR ALLOW MORE.
 *
 * It matters for money. A truncated call is billed in full for output the caller
 * throws away, and it is the most expensive way to fail because truncation only
 * happens on the LARGEST requests. Reporting it as a parse error hides the one
 * detail that would stop it happening again.
 *
 * Carries the token counts so the caller can say what it cost and what to do.
 */
class DeepSeekTruncatedException extends RuntimeException
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly int $maxTokens,
    ) {
        parent::__construct(sprintf(
            'DeepSeek stopped at the %d-token output limit before finishing, so the '
            . 'answer is incomplete and cannot be used. It still cost %d prompt and '
            . '%d completion tokens. Send fewer items in one call, or raise max_tokens.',
            $maxTokens, $promptTokens, $completionTokens
        ));
    }
}
