<?php

namespace App\Support;

/**
 * SMS segment counting (spec Section 7.4): segments are computed at
 * send time per GSM-7 / UCS-2 rules. GSM-7 packs 160 characters in a
 * single segment and 153 per segment when concatenated; UCS-2 packs
 * 70 and 67. Extension-table characters cost two GSM-7 units.
 */
final class SmsSegments
{
    private const GSM7_EXTENSION = '^{}\\[~]|€';

    public static function count(string $body): int
    {
        if ($body === '') {
            return 0;
        }

        $units = self::gsmUnits($body);

        if ($units !== null) {
            return $units <= 160 ? 1 : (int) ceil($units / 153);
        }

        // UCS-2 is billed in UTF-16 code units: a character above
        // the BMP (an emoji) is a surrogate pair and costs two.
        $length = intdiv(strlen(mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2);

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }

    /**
     * The encoding a body bills under: GSM-7 when every character
     * is in the GSM tables, Unicode otherwise. The campaign
     * estimate names it because one emoji flips the whole body
     * to UCS-2 segment math.
     */
    public static function encoding(string $body): string
    {
        return self::gsmUnits($body) !== null ? 'GSM-7' : 'Unicode';
    }

    /**
     * GSM-7 unit count, or null when the body carries any character
     * outside the GSM-7 basic and extension tables (forcing UCS-2).
     */
    private static function gsmUnits(string $body): ?int
    {
        $units = 0;

        foreach (mb_str_split($body) as $char) {
            if (str_contains(self::GSM7_EXTENSION, $char)) {
                $units += 2;
            } elseif (self::isGsmBasic($char)) {
                $units += 1;
            } else {
                return null;
            }
        }

        return $units;
    }

    private static function isGsmBasic(string $char): bool
    {
        if (mb_strlen($char) !== 1) {
            return false;
        }

        $code = mb_ord($char);

        // Printable ASCII (the GSM-7 basic table covers it except
        // the extension set above and the backtick, which is not in
        // the table at all), plus the table's Latin-1 and Greek
        // letters in the ranges the standard defines.
        return ($code >= 0x20 && $code <= 0x7A && ! in_array($char, ['[', ']', '\\', '`'], true))
            || in_array($char, ['¡', '¿', '£', '¥', '§', '¤', 'à', 'ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', 'é', 'è', 'ù', 'ì', 'ò', 'Ç', 'Ø', 'ø', 'Å', 'å', 'Æ', 'æ', 'É', 'ñ', 'Ñ', 'Δ', 'Φ', 'Γ', 'Λ', 'Ω', 'Π', 'Ψ', 'Σ', 'Θ', 'Ξ'], true)
            || $char === "\n" || $char === "\r";
    }
}
