<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTemplate extends Model
{
    use HasFactory;

    protected $table = 'email_templates';

    protected $fillable = [
        'app_id',
        'type',
        'subject',
        'body',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the app that owns this email template.
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    /**
     * Scope a query to only include active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to get templates for a specific app.
     */
    public function scopeForApp($query, int $appId)
    {
        return $query->where('app_id', $appId);
    }

    /**
     * Replace variables in the email body with actual values.
     *
     * The body is sent as HTML, and the values are plain text that merchants
     * control (store names, feature request titles and descriptions), so each
     * one is escaped: a store named "<a href=...>" must arrive as text, not as
     * a link in our email.
     *
     * @param array $variables Key-value pairs to replace in template
     * @return string
     */
    public function renderBody(array $variables = []): string
    {
        return $this->replaceVariables($this->body, $variables, fn ($value) => e((string) $value));
    }

    /**
     * Replace variables in the email subject with actual values.
     *
     * Not escaped: the subject is a plain-text header, where "&amp;" would be
     * shown to the reader literally.
     *
     * @param array $variables Key-value pairs to replace in template
     * @return string
     */
    public function renderSubject(array $variables = []): string
    {
        return $this->replaceVariables($this->subject, $variables, fn ($value) => (string) $value);
    }

    /**
     * Single pass (strtr), so a value that itself contains "{{store_url}}"
     * stays as typed instead of being expanded by a later replacement.
     */
    private function replaceVariables(string $text, array $variables, callable $format): string
    {
        $pairs = [];

        foreach ($variables as $key => $value) {
            $pairs['{{' . $key . '}}'] = $format($value);
        }

        return strtr($text, $pairs);
    }

    /**
     * Get rendered email with replaced variables.
     * 
     * @param array $variables Key-value pairs to replace in template
     * @return array
     */
    public function render(array $variables = []): array
    {
        return [
            'subject' => $this->renderSubject($variables),
            'body' => $this->renderBody($variables),
        ];
    }
}
