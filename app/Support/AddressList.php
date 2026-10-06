<?php

namespace App\Support;

/**
 * Comma-separated email address lists.
 *
 * The form in which this app has always stored multi-recipient fields - the
 * mail providers' own cc/bcc settings use it too - so parsing lives in one
 * place rather than being re-implemented beside each field.
 */
final class AddressList
{
    /**
     * Split a stored list into individual addresses.
     *
     * Trims, drops blanks, and removes duplicates case-insensitively so that
     * "a@x.com, A@X.com" cannot mail the same person twice.
     *
     * @param  string|array<int, string|null>|null  $addresses
     * @return array<int, string>
     */
    public static function parse(string|array|null $addresses): array
    {
        if ($addresses === null) {
            return [];
        }

        $parts = is_array($addresses)
            ? $addresses
            : (preg_split('/[,;]+/', $addresses) ?: []);

        $seen = [];

        foreach ($parts as $part) {
            $address = trim((string) $part);

            if ($address === '') {
                continue;
            }

            $seen[mb_strtolower($address)] ??= $address;
        }

        return array_values($seen);
    }

    /**
     * Normalise a list back to the stored form, or null when it is empty.
     *
     * @param  string|array<int, string|null>|null  $addresses
     */
    public static function normalise(string|array|null $addresses): ?string
    {
        $parsed = self::parse($addresses);

        return $parsed === [] ? null : implode(', ', $parsed);
    }
}
