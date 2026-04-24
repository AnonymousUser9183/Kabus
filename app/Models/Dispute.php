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

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Dispute extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_VENDOR_PREVAILS = 'vendor_prevails';
    public const STATUS_BUYER_PREVAILS = 'buyer_prevails';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'order_id',
        'status',
        'reason',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'resolved_at' => 'datetime',
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
     * Get the order that owns the dispute.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    /**
     * Get the admin user who resolved the dispute.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the messages for this dispute.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class, 'dispute_id')
        ->orderBy('created_at', 'asc');
    }

    /**
     * Get the formatted status.
     */
    public function getFormattedStatus(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active Dispute',
            self::STATUS_VENDOR_PREVAILS => 'Vendor Prevails',
            self::STATUS_BUYER_PREVAILS => 'Buyer Prevails',
            default => 'Unknown Status',
        };
    }

    /**
     * Resolve the dispute with vendor prevailing.
     */
    public function resolveVendorPrevails(string $adminId): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->status = self::STATUS_VENDOR_PREVAILS;
        $this->resolved_at = now();
        $this->resolved_by = $adminId;
        $this->save();

        $this->order?->markAsCompleted();

        return true;
    }

    /**
     * Resolve the dispute with buyer prevailing.
     */
    public function resolveBuyerPrevails(string $adminId): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->status = self::STATUS_BUYER_PREVAILS;
        $this->resolved_at = now();
        $this->resolved_by = $adminId;
        $this->save();

        $this->order?->markAsCancelled();

        return true;
    }

    /**
     * Get all disputes for the admin.
     *
     * @return Collection<int, self>
     */
    public static function getAllDisputes(): Collection
    {
        return self::query()
        ->with(['order', 'order.user', 'order.vendor'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Get active disputes for the admin.
     *
     * @return Collection<int, self>
     */
    public static function getActiveDisputes(): Collection
    {
        return self::query()
        ->where('status', self::STATUS_ACTIVE)
        ->with(['order', 'order.user', 'order.vendor'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Get resolved disputes for the admin.
     *
     * @return Collection<int, self>
     */
    public static function getResolvedDisputes(): Collection
    {
        return self::query()
        ->whereIn('status', [self::STATUS_VENDOR_PREVAILS, self::STATUS_BUYER_PREVAILS])
        ->with(['order', 'order.user', 'order.vendor', 'resolver'])
        ->orderBy('resolved_at', 'desc')
        ->get();
    }

    /**
     * Get all disputes for a user as buyer.
     *
     * @return Collection<int, self>
     */
    public static function getUserDisputes(string $userId): Collection
    {
        return self::query()
        ->whereHas('order', function ($query) use ($userId): void {
            $query->where('user_id', $userId);
        })
        ->with(['order', 'order.vendor'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Get all disputes for a vendor.
     *
     * @return Collection<int, self>
     */
    public static function getVendorDisputes(string $vendorId): Collection
    {
        return self::query()
        ->whereHas('order', function ($query) use ($vendorId): void {
            $query->where('vendor_id', $vendorId);
        })
        ->with(['order', 'order.user'])
        ->orderBy('created_at', 'desc')
        ->get();
    }
}
