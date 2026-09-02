<?php

namespace App\Support\Board;

use App\Models\App;
use App\Models\Installation;

/**
 * The verified store behind a board session. Built only from an HMAC-signed
 * token, so `voterKey` can be trusted as the store's real identity.
 */
class BoardIdentity
{
    public function __construct(
        public readonly App $app,
        public readonly string $voterKey,
        public readonly ?int $installationId = null,
        public readonly ?string $storeName = null,
        public readonly ?string $email = null,
    ) {
    }

    public function installation(): ?Installation
    {
        return $this->installationId ? Installation::find($this->installationId) : null;
    }

    /**
     * Attributes stamped onto every request and vote this store creates.
     *
     * @return array<string, mixed>
     */
    public function attribution(): array
    {
        return [
            'app_id' => $this->app->id,
            'installation_id' => $this->installationId,
            'submitter_shop_domain' => $this->voterKey,
            'submitter_email' => $this->email,
        ];
    }

    /**
     * Public-facing description of who is voting, shown in the board header.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shop_domain' => $this->voterKey,
            'store_name' => $this->storeName,
            'is_installed' => $this->installationId !== null,
        ];
    }
}
