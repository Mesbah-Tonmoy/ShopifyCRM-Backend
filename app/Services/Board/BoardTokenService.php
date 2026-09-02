<?php

namespace App\Services\Board;

use App\Exceptions\BoardAuthException;
use App\Models\App;
use App\Models\Installation;
use App\Support\Board\BoardIdentity;
use App\Support\ShopDomain;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Verifies the HMAC-signed tokens minted by each Shopify app, and exchanges
 * them for opaque board sessions.
 *
 * The signing secret lives only on the app's server and in this CRM, so a
 * browser can never assert a shop domain it does not own.
 */
class BoardTokenService
{
    /**
     * Verify an app-signed token and resolve the store behind it.
     *
     * Token format: base64url(json payload) . "." . hex hmac-sha256
     *
     * @throws BoardAuthException
     */
    public function verifyAppToken(string $token): BoardIdentity
    {
        [$encodedPayload, $signature] = $this->split($token);

        $payload = $this->decodePayload($encodedPayload);

        $app = App::where('board_public_key', $payload['key'] ?? '')->first();

        if (! $app || ! $app->hasBoardCredentials()) {
            throw new BoardAuthException('Unknown board key.');
        }

        $expected = hash_hmac('sha256', $encodedPayload, $app->board_secret);

        if (! hash_equals($expected, (string) $signature)) {
            throw new BoardAuthException('Board token signature mismatch.');
        }

        $this->assertFresh($payload);

        $shopDomain = ShopDomain::normalize($payload['shop'] ?? null);

        if ($shopDomain === null) {
            throw new BoardAuthException('Board token is missing a shop domain.');
        }

        return $this->buildIdentity($app, $shopDomain, $payload);
    }

    /**
     * Mint an opaque session string for the verified store. Encrypted with the
     * app key, so its contents cannot be read or altered by the browser.
     */
    public function issueSession(BoardIdentity $identity): string
    {
        return Crypt::encryptString(json_encode([
            'app_id' => $identity->app->id,
            'voter_key' => $identity->voterKey,
            'installation_id' => $identity->installationId,
            'store_name' => $identity->storeName,
            'email' => $identity->email,
            'exp' => now()->addSeconds((int) config('board.session_ttl'))->getTimestamp(),
        ]));
    }

    /**
     * Rebuild the identity from a session string, or null when it is absent,
     * tampered with, expired, or points at a different app than the one being
     * viewed.
     */
    public function readSession(?string $session, ?App $expectedApp = null): ?BoardIdentity
    {
        if (blank($session)) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($session), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException | \JsonException) {
            return null;
        }

        if (! is_array($data) || ($data['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        if ($expectedApp && (int) ($data['app_id'] ?? 0) !== $expectedApp->id) {
            return null;
        }

        $app = $expectedApp ?: App::find($data['app_id'] ?? null);

        if (! $app || blank($data['voter_key'] ?? null)) {
            return null;
        }

        return new BoardIdentity(
            app: $app,
            voterKey: $data['voter_key'],
            installationId: $data['installation_id'] ?? null,
            storeName: $data['store_name'] ?? null,
            email: $data['email'] ?? null,
        );
    }

    /* -----------------------------------------------------------------
     | Internals
     | -----------------------------------------------------------------
     */

    /**
     * @return array{0: string, 1: string}
     *
     * @throws BoardAuthException
     */
    protected function split(string $token): array
    {
        $parts = explode('.', trim($token));

        if (count($parts) !== 2 || blank($parts[0]) || blank($parts[1])) {
            throw new BoardAuthException('Malformed board token.');
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws BoardAuthException
     */
    protected function decodePayload(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($json === false) {
            throw new BoardAuthException('Malformed board token.');
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new BoardAuthException('Malformed board token.');
        }

        return $payload;
    }

    /**
     * Reject tokens that have expired, or that were issued implausibly far in
     * the future by a badly skewed clock.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws BoardAuthException
     */
    protected function assertFresh(array $payload): void
    {
        $now = now()->getTimestamp();
        $skew = (int) config('board.clock_skew');

        $expiry = isset($payload['exp'])
            ? (int) $payload['exp']
            : (int) ($payload['iat'] ?? 0) + (int) config('board.token_ttl');

        if ($expiry <= 0 || $expiry + $skew < $now) {
            throw new BoardAuthException('Board token has expired.');
        }

        if (isset($payload['iat']) && (int) $payload['iat'] - $skew > $now) {
            throw new BoardAuthException('Board token was issued in the future.');
        }
    }

    /**
     * Match the store to an installation so votes can be attributed, and the
     * store can be emailed when a request it backed ships.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function buildIdentity(App $app, string $shopDomain, array $payload): BoardIdentity
    {
        $installation = $this->resolveInstallation($app, $shopDomain, $payload);

        return new BoardIdentity(
            app: $app,
            voterKey: $shopDomain,
            installationId: $installation?->id,
            storeName: $installation?->store_name
                ?: ($payload['name'] ?? ShopDomain::toStoreName($shopDomain)),
            email: $installation?->email ?: ($payload['email'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveInstallation(App $app, string $shopDomain, array $payload): ?Installation
    {
        $installation = Installation::where('app_id', $app->id)
            ->where(fn ($query) => $query
                ->where('store_url', $shopDomain)
                ->orWhere('store_url', 'like', "%{$shopDomain}%"))
            ->first();

        if ($installation || ! config('board.auto_create_installations')) {
            return $installation;
        }

        return Installation::create([
            'app_id' => $app->id,
            'store_url' => $shopDomain,
            'store_name' => $payload['name'] ?? ShopDomain::toStoreName($shopDomain),
            'email' => $payload['email'] ?? null,
            'is_active' => true,
        ]);
    }
}
