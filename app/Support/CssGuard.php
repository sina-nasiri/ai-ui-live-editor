<?php

namespace App\Support;

/**
 * Filters the CSS declarations a model returns before they reach the page.
 *
 * The preview has no scripts, so the blast radius is small, but a model can
 * still hand back a malformed property that breaks the whole patch
 * stylesheet, or a `url()` pointing somewhere unexpected. Dropping a bad
 * declaration is always better than dropping the edit.
 */
class CssGuard
{
    private const MAX_VALUE_LENGTH = 400;

    /**
     * @param  array<int,mixed>  $declarations
     * @return list<array{property:string,value:string}>
     */
    public static function filter(array $declarations): array
    {
        $clean = [];

        foreach ($declarations as $declaration) {
            if (! is_array($declaration)) {
                continue;
            }

            $property = strtolower(trim((string) ($declaration['property'] ?? '')));
            $value = trim((string) ($declaration['value'] ?? ''));

            if (! self::isValidProperty($property) || ! self::isValidValue($value)) {
                continue;
            }

            // The patch stylesheet is already last in the cascade, so
            // !important only makes the result harder to override later.
            $value = trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value);

            if ($value === '') {
                continue;
            }

            $clean[] = ['property' => $property, 'value' => $value];
        }

        return $clean;
    }

    private static function isValidProperty(string $property): bool
    {
        // Standard properties (font-size) plus custom properties (--brand).
        return $property !== '' && preg_match('/^(--)?[a-z][a-z0-9-]{0,60}$/', $property) === 1;
    }

    private static function isValidValue(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_VALUE_LENGTH) {
            return false;
        }

        // A brace or semicolon would let one declaration escape its rule and
        // write arbitrary CSS elsewhere in the sheet.
        if (preg_match('/[{}<>;]/', $value) === 1) {
            return false;
        }

        return preg_match('/(javascript:|vbscript:|expression\s*\(|@import|behavior\s*:)/i', $value) !== 1;
    }
}
