<?php

namespace App\Helpers;

/**
 * Sanitizes user-provided strings for CSV export to prevent
 * CSV/formula injection attacks (Excel treats leading =, +, -, @ as formulas).
 */
class CsvSanitizer
{
    /**
     * Neutralize a cell value that could start with a formula-trigger character.
     *
     * @param  mixed  $value
     * @param  bool   $quote  Wrap in double quotes (caller should not double-quote again)
     */
    public static function cell(mixed $value, bool $quote = true): string
    {
        $string = (string) ($value ?? '');

        // Formula injection characters (Excel/Sheets)
        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $string = "'" . $string;
        }

        // Escape embedded double quotes and wrap
        if ($quote) {
            return '"' . str_replace('"', '""', $string) . '"';
        }

        return $string;
    }
}
