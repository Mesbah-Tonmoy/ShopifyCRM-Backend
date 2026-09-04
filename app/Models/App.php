<?php

namespace App\Models;

use App\Enums\FeatureRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class App extends Model
{
    use HasFactory;

    protected $table = 'apps';

    protected $fillable = [
        'id',
        'app_name',
        'app_url',
        'app_store_url',
        'icon',
        'last_synced',
        'board_slug',
        'board_public_key',
        'board_secret',
    ];

    protected $casts = [
        'last_synced' => 'datetime',
        'board_secret' => 'encrypted',
    ];

    /**
     * The signing secret must never reach an API response. It is revealed
     * only by the explicit rotate endpoint, which returns it directly.
     */
    protected $hidden = [
        'board_secret',
    ];

    public function installations(): HasMany
    {
        return $this->hasMany(Installation::class, 'app_id');
    }

    /**
     * Get active installations for the app.
     */
    public function activeInstallations(): HasMany
    {
        return $this->hasMany(Installation::class)->where('is_active', true);
    }

    /**
     * Get email templates for the app.
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'app_id');
    }

    /**
     * Get pricing plans for the app.
     */
    public function pricingPlans(): HasMany
    {
        return $this->hasMany(PricingPlan::class, 'app_id');
    }

    /**
     * Get the feature request board configuration for the app.
     */
    public function board(): HasOne
    {
        return $this->hasOne(FeatureBoard::class, 'app_id');
    }

    /**
     * Get feature requests submitted for the app.
     */
    public function featureRequests(): HasMany
    {
        return $this->hasMany(FeatureRequest::class, 'app_id');
    }

    /**
     * Resolve an app by its public board slug.
     */
    public function scopeByBoardSlug(Builder $query, string $slug): Builder
    {
        return $query->where('board_slug', $slug);
    }

    /**
     * Whether the app is ready to accept signed board tokens.
     */
    public function hasBoardCredentials(): bool
    {
        return filled($this->board_public_key) && filled($this->board_secret);
    }

    /**
     * Create the board row and signing credentials on first use. Existing
     * credentials are left untouched so the apps already embedding the
     * board keep working.
     */
    public function provisionBoard(): FeatureBoard
    {
        if (blank($this->board_slug)) {
            $this->board_slug = static::generateBoardSlug($this->app_name, $this->id);
        }

        if (! $this->hasBoardCredentials()) {
            $this->board_public_key = 'bk_' . Str::lower(Str::random(32));
            $this->board_secret = Str::random(64);
        }

        $this->save();

        return $this->board()->firstOrCreate([], [
            'title' => $this->app_name,
            'visible_statuses' => FeatureRequestStatus::defaultVisible(),
        ]);
    }

    /**
     * Issue a fresh signing secret, invalidating tokens signed with the old one.
     * Returns the plaintext secret, which is shown to the admin exactly once.
     */
    public function rotateBoardSecret(): string
    {
        $secret = Str::random(64);

        $this->forceFill(['board_secret' => $secret])->save();

        return $secret;
    }

    /**
     * Build a slug that is unique across apps.
     */
    public static function generateBoardSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'board';
        $slug = $base;
        $suffix = 2;

        while (static::where('board_slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Get published "what's new" entries for the app. Separate from feature
     * requests: an update can ship without anyone having asked for it.
     */
    public function features(): HasMany
    {
        return $this->hasMany(Feature::class, 'app_id');
    }

    /**
     * Get total installation count.
     */
    public function getTotalInstallationsAttribute(): int
    {
        return $this->installations()->count();
    }

    /**
     * Get active installation count.
     */
    public function getActiveInstallationsCountAttribute(): int
    {
        return $this->activeInstallations()->count();
    }
}
