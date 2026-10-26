<?php

namespace App\Services\Workflows\Execution;

/**
 * Compares actual numbers without treating numeric strings as numbers.
 */
class NumericValue
{
    public static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && abs($value) <= 1_000_000_000;
    }

    public static function normalize(int|float $value): int|float
    {
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return $value;
    }

    public static function compare(int|float $left, int|float $right): int
    {
        $leftParts = self::parts($left);
        $rightParts = self::parts($right);

        if ($leftParts['negative'] !== $rightParts['negative']) {
            return $leftParts['negative'] ? -1 : 1;
        }

        $sign = $leftParts['negative'] ? -1 : 1;
        $leftInt = ltrim($leftParts['integer'], '0') ?: '0';
        $rightInt = ltrim($rightParts['integer'], '0') ?: '0';

        if (strlen($leftInt) !== strlen($rightInt)) {
            return $sign * (strlen($leftInt) <=> strlen($rightInt));
        }

        if ($leftInt !== $rightInt) {
            return $sign * ($leftInt <=> $rightInt);
        }

        $leftFraction = str_pad($leftParts['fraction'], 6, '0');
        $rightFraction = str_pad($rightParts['fraction'], 6, '0');

        return $sign * (substr($leftFraction, 0, 6) <=> substr($rightFraction, 0, 6));
    }

    /**
     * @return array{negative: bool, integer: string, fraction: string}
     */
    private static function parts(int|float $value): array
    {
        if (is_int($value)) {
            $text = (string) abs($value);

            return [
                'negative' => $value < 0,
                'integer' => $text,
                'fraction' => '',
            ];
        }

        $negative = $value < 0;
        $text = number_format(abs($value), 6, '.', '');
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        return [
            'negative' => $negative && ($integer !== '0' || $fraction !== ''),
            'integer' => $integer === '' ? '0' : $integer,
            'fraction' => $fraction,
        ];
    }
}
