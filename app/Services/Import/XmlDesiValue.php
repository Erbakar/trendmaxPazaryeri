<?php
declare(strict_types=1);

namespace App\Services\Import;

/** Desi must be a measurement, never digits extracted from an SKU or HTML. */
final class XmlDesiValue
{
    public static function parse(string $raw): float
    {
        $raw = trim($raw);
        if (!preg_match('/^([+]?[0-9]+(?:[.,][0-9]+)*)\s*(?:des[iİı])?$/iuD', $raw, $match)) {
            return 0.0;
        }
        $number = $match[1];
        if (str_contains($number, ',') && str_contains($number, '.')) {
            if (!preg_match('/^[+]?(?:[0-9]{1,3}(?:\.[0-9]{3})+,[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+\.[0-9]+)$/D', $number)) {
                return 0.0;
            }
            $number = strrpos($number, ',') > strrpos($number, '.')
                ? str_replace(['.', ','], ['', '.'], $number)
                : str_replace(',', '', $number);
        } elseif (substr_count($number, '.') > 1 || substr_count($number, ',') > 1) {
            // Do not guess the meaning of malformed values such as 1.2.3.
            if (!preg_match('/^[+]?[0-9]{1,3}(?:([.,])[0-9]{3})(?:\1[0-9]{3})+$/D', $number)) {
                return 0.0;
            }
            $number = str_replace([',', '.'], '', $number);
        } else {
            $number = str_replace(',', '.', $number);
        }
        return min((float) $number, 999999.99);
    }
}
