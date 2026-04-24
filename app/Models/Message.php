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

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Message extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'content',
        'is_read',
        'user_id_1',
        'user_id_2',
        'last_message_at',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    /**
     * Parent conversation record.
     */
    public function parentConversation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conversation_id', 'id')
        ->whereNull('conversation_id');
    }

    /**
     * Message sender.
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Get decrypted content.
     */
    public function getContentAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Exception $e) {
            Log::error('Failed to decrypt message: '.$e->getMessage());
            return 'Error: Unable to decrypt message';
        }
    }

    /**
     * Set encrypted content.
     *
     * @throws Exception
     */
    public function setContentAttribute($value): void
    {
        if (! $value) {
            $this->attributes['content'] = null;
            return;
        }

        try {
            $this->attributes['content'] = Crypt::encryptString($value);
        } catch (Exception $e) {
            Log::error('Failed to encrypt message: '.$e->getMessage());
            throw new Exception('Failed to encrypt message. Please try again.');
        }
    }

    /**
     * Child messages in the conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(self::class, 'conversation_id')
        ->whereNotNull('conversation_id');
    }

    /**
     * First conversation participant.
     */
    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_1');
    }

    /**
     * Second conversation participant.
     */
    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id_2');
    }

    /**
     * Check whether the conversation has reached the message limit.
     */
    public function hasReachedMessageLimit(): bool
    {
        return $this->messages()->count() >= 40;
    }

    /**
     * Scope for regular messages only.
     */
    public function scopeRegularMessage(Builder $query): Builder
    {
        return $query->whereNotNull('conversation_id');
    }

    /**
     * Scope for conversation roots only.
     */
    public function scopeConversation(Builder $query): Builder
    {
        return $query->whereNull('conversation_id');
    }

    /**
     * Determine if this record is a conversation root.
     */
    public function isConversation(): bool
    {
        return $this->conversation_id === null;
    }

    /**
     * Create a new conversation.
     */
    public static function createConversation(string $userId1, string $userId2): self
    {
        $conversation = new static();
        $conversation->user_id_1 = $userId1;
        $conversation->user_id_2 = $userId2;
        $conversation->last_message_at = now();
        $conversation->save();

        return $conversation;
    }

    /**
     * Find an existing conversation between two users.
     */
    public static function findConversation(string $userId1, string $userId2): ?self
    {
        return static::query()
        ->conversation()
        ->where(function (Builder $query) use ($userId1, $userId2): void {
            $query->where('user_id_1', $userId1)
            ->where('user_id_2', $userId2);
        })
        ->orWhere(function (Builder $query) use ($userId1, $userId2): void {
            $query->where('user_id_1', $userId2)
            ->where('user_id_2', $userId1);
        })
        ->first();
    }
}
