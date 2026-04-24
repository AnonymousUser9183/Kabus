<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SupportRequest extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'ticket_id',
        'message',
        'is_admin_reply',
        'parent_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->parent_id) && empty($model->ticket_id)) {
                $model->ticket_id = self::generateUniqueTicketId();
            }

            if (! empty($model->message)) {
                $model->message = self::sanitizeMessage($model->message);
            }
        });

        static::updating(function (self $model): void {
            if (! empty($model->message)) {
                $model->message = self::sanitizeMessage($model->message);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
        ->orderBy('created_at', 'asc');
    }

    public function parentRequest(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(self::class, 'parent_id')->latestOfMany();
    }

    /**
     * Sanitize the message content to prevent XSS and other injection attacks.
     */
    private static function sanitizeMessage(string $message): string
    {
        $message = strip_tags($message);
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = str_replace(chr(0), '', $message);
        $message = preg_replace('/[^\P{C}\n]+/u', '', $message) ?? $message;
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace("/\n{3,}/", "\n\n", $message) ?? $message;

        return trim($message);
    }

    /**
     * Get the sanitized message for display.
     */
    public function getFormattedMessageAttribute(): HtmlString
    {
        return new HtmlString(nl2br($this->message ?? ''));
    }

    public static function generateUniqueTicketId(): string
    {
        do {
            $ticketId = Str::random(30);
        } while (static::where('ticket_id', $ticketId)->exists());

        return $ticketId;
    }

    public function getRouteKeyName(): string
    {
        return 'ticket_id';
    }

    /**
     * Scope a query to only include main support requests.
     */
    public function scopeMainRequests(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Check if this is a message.
     */
    public function isMessage(): bool
    {
        return ! is_null($this->parent_id);
    }

    /**
     * Check if this is a main support request.
     */
    public function isMainRequest(): bool
    {
        return is_null($this->parent_id);
    }
}
