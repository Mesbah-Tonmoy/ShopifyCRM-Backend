<?php

namespace App\Support;

/**
 * Normalises Shopify shop domains into the canonical form used as the
 * board's voter identity, so "https://Acme.myshopify.com/" and
 * "acme.myshopify.com" are always recognised as the same store.
 */
class ShopDomain
{
    public static function normalize(?string $domain): ?string
    {
        if (blank($domain)) {
            return null;
        }

        // Drop scheme, path, query and any credentials, keeping the host only.
        $host = parse_url(
            str_contains($domain, '//') ? $domain : '//' . ltrim($domain, '/'),
            PHP_URL_HOST
        ) ?: $domain;

        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim($host, '.');

        return $host !== '' ? $host : null;
    }

    /**
     * Best-effort store label for a domain, used when a store submits a
     * request before its installation record exists.
     */
    public static function toStoreName(?string $domain): ?string
    {
        $host = static::normalize($domain);

        if ($host === null) {
            return null;
        }

        $label = str_replace('.myshopify.com', '', $host);

        return str($label)->replace('-', ' ')->title()->value();
    }
}
