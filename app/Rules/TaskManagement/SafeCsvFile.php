<?php

namespace App\Rules\TaskManagement;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class SafeCsvFile implements ValidationRule
{
    /**
     * Hard cap on data rows so a huge file can't hang the import.
     */
    private const MAX_ROWS = 2000;

    /**
     * Classic CSV-formula-injection prefixes. '-' is deliberately excluded —
     * it is a normal leading character for negative numbers and some date
     * formats in this importer's columns, and would just produce false
     * positives on legitimate data.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '@'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        $handle = @fopen($value->getRealPath(), 'r');
        if ($handle === false) {
            $fail('The :attribute could not be read.');

            return;
        }

        try {
            $header = fgetcsv($handle, 0, ',');
            if ($header === false || count($header) === 0) {
                $fail('The :attribute is empty.');

                return;
            }

            $rowNum = 1;
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                $rowNum++;

                if ($rowNum - 1 > self::MAX_ROWS) {
                    $fail("The :attribute has more than " . self::MAX_ROWS . ' data rows. Please split it into smaller files.');

                    return;
                }

                foreach ($row as $cell) {
                    $cell = ltrim((string) $cell);
                    if ($cell !== '' && in_array($cell[0], self::DANGEROUS_PREFIXES, true)) {
                        $fail("The :attribute contains unsafe formula content in row {$rowNum} and cannot be imported.");

                        return;
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
