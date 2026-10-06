<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The account balance is at or below the configured floor — refuse to spend.
 *
 * ── WHY A FLOOR RATHER THAN WAITING FOR ZERO ────────────────────────────────
 *
 * DeepSeek already refuses at zero, with HTTP 402. That protects DeepSeek, not
 * the account holder: by the time it fires, the credit is gone.
 *
 * The floor exists so that some credit is still there afterwards. A run that
 * spends down to nothing has obeyed the letter of "don't spend it all" and
 * missed the point, and it leaves every OTHER feature in the app - assessment
 * generation, marking, course outlines - unable to make a single call.
 *
 * Set with DEEPSEEK_MIN_BALANCE_USD. Checked in DeepSeekService so it guards
 * every call site at once rather than only the one that remembered to ask.
 */
class DeepSeekBudgetException extends RuntimeException
{
    public function __construct(
        public readonly float $balance,
        public readonly float $floor,
        public readonly string $currency = 'USD',
    ) {
        parent::__construct(sprintf(
            'Refusing to call DeepSeek: the balance is %s %.2f, at or below the %s %.2f '
            . 'floor set by DEEPSEEK_MIN_BALANCE_USD. Nothing was sent and nothing was '
            . 'charged. Top up the account, or lower the floor if this is deliberate.',
            $currency, $balance, $currency, $floor
        ));
    }
}
