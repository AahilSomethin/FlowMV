<?php

namespace App\Support;

final class CsvCell
{
    public static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

        if (preg_match('/^[=+\-@]/', $text) === 1) {
            return "'".$text;
        }

        return $text;
    }
}
