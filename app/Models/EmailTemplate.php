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
     * @param array $variables Key-value pairs to replace in template
     * @return string
     */
    public function renderBody(array $variables = []): string
    {
        $body = $this->body;
        
        foreach ($variables as $key => $value) {
            $body = str_replace('{{' . $key . '}}', $value, $body);
        }
        
        return $body;
    }

    /**
     * Replace variables in the email subject with actual values.
     * 
     * @param array $variables Key-value pairs to replace in template
     * @return string
     */
    public function renderSubject(array $variables = []): string
    {
        $subject = $this->subject;
        
        foreach ($variables as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }
        
        return $subject;
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
