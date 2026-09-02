<?php

namespace App\Enums;

/**
 * Lifecycle of a merchant-submitted feature request.
 *
 * This is the single source of truth for board statuses: validation rules,
 * roadmap column defaults, and notification template names all derive from it.
 */
enum FeatureRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';

    /**
     * Human readable label used by the admin UI and the public board.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * Email template type fired when a request enters this status.
     */
    public function templateType(): string
    {
        return 'feature_request_' . $this->value;
    }

    /**
     * Whether entering this status should notify everyone who voted,
     * rather than the submitting store alone.
     */
    public function notifiesVoters(): bool
    {
        return in_array($this, [self::InProgress, self::Completed], true);
    }

    /**
     * Whether a request in this status is shown on the public board once approved.
     * Pending requests stay hidden until an admin lets them through.
     */
    public function isPubliclyListed(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * All status values, for validation rules and filters.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Value/label pairs for select inputs and board configuration.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases()
        );
    }

    /**
     * Default roadmap column order for a newly created board.
     *
     * @return array<int, string>
     */
    public static function defaultVisible(): array
    {
        return self::values();
    }
}
