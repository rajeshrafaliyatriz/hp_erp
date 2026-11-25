<?php

namespace App\Domain\Money;

/**
 * How an amount is written on a printed document (the quote and invoice PDFs; S16, 25 Nov 2026, contract section 4 (c)): the symbol of the document's own currency in front of
 * the number, the number with the digits that currency keeps. A currency outside the catalogue (legacy text) is written as its code in front ("RUPEES 1,234.50"); a document with no
 * currency has a bare number, as it always had. A symbol made of letters is followed by a space ("CHF 1,200.00"), a sign is not ("₹1,200.00").
 */
final class MoneyFormat
{
    public static function amount(mixed $value, mixed $currency): string
    {
        $number = (float) $value;
        $decimals = Currencies::decimals($currency);
        $text = number_format(abs($number), $decimals);
        $sign = $number < 0 ? '-' : '';
        $symbol = Currencies::symbol($currency);
        if ($symbol === '') {
            return $sign . $text;
        }

        return $sign . $symbol . (preg_match('/\p{L}$/u', $symbol) === 1 ? ' ' : '') . $text;
    }

    /**
     * The tax of a line as the document shows it: "GST 18%". The name is the rate's own at the time the line was saved (the snapshot), the percentage is the one it carried; when the name
     * already says the percentage ("GST 18%") it is not said twice. A line with no tax is a dash.
     */
    public static function taxLabel(?string $name, mixed $percent): string
    {
        if ($percent === null || $percent === '') {
            return '-';
        }
        $pct = rtrim(rtrim(number_format((float) $percent, 3, '.', ''), '0'), '.') . '%';
        $name = trim((string) $name);
        if ($name === '') {
            return $pct;
        }

        return str_contains($name, $pct) ? $name : $name . ' ' . $pct;
    }
}
