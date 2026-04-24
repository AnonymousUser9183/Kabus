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

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DisputeMessage extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'dispute_id',
        'user_id',
        'message',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = Str::random(30);
            }
        });
    }

    /**
     * Get the dispute that owns the message.
     */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class, 'dispute_id');
    }

    /**
     * Get the user who sent the message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Check if the message is from an admin.
     */
    public function isFromAdmin(): bool
    {
        return $this->user && $this->user->hasRole('admin');
    }

    /**
     * Check if the message is from the buyer.
     */
    public function isFromBuyer(): bool
    {
        if (! $this->user || ! $this->dispute || ! $this->dispute->order) {
            return false;
        }

        return $this->user->id === $this->dispute->order->user_id;
    }

    /**
     * Check if the message is from the vendor.
     */
    public function isFromVendor(): bool
    {
        if (! $this->user || ! $this->dispute || ! $this->dispute->order) {
            return false;
        }

        return $this->user->id === $this->dispute->order->vendor_id;
    }

    /**
     * Get the message type for UI display.
     */
    public function getMessageType(): string
    {
        if ($this->isFromAdmin()) {
            return 'admin';
        }

        if ($this->isFromBuyer()) {
            return 'buyer';
        }

        if ($this->isFromVendor()) {
            return 'vendor';
        }

        return 'unknown';
    }
}
