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

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class Advertisement extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id',
        'user_id',
        'slot_number',
        'duration_days',
        'starts_at',
        'ends_at',
        'payment_identifier',
        'payment_address',
        'payment_address_index',
        'total_received',
        'required_amount',
        'payment_completed',
        'payment_completed_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_number' => 'integer',
            'duration_days' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'total_received' => 'decimal:12',
            'required_amount' => 'decimal:12',
            'payment_completed' => 'boolean',
            'payment_completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Register model events.
     */
    protected static function booted(): void
    {
        static::creating(function (self $advertisement): void {
            if (empty($advertisement->payment_identifier)) {
                $advertisement->payment_identifier = str()->random(64);
            }
        });
    }

    /**
     * Calculate the required payment amount for an advertisement.
     */
    public static function calculateRequiredAmount(int $slotNumber, int $durationDays): float
    {
        $basePrice = config('monero.advertisement_base_price');
        $multipliers = config('monero.advertisement_slot_multipliers');

        if (! isset($multipliers[$slotNumber])) {
            throw new InvalidArgumentException('Invalid slot number');
        }

        return $basePrice * $multipliers[$slotNumber] * $durationDays;
    }

    /**
     * Check if the slot is available for the given time period.
     */
    public static function isSlotAvailable(int $slotNumber, Carbon $startDate, Carbon $endDate): bool
    {
        return ! static::query()
        ->where('slot_number', $slotNumber)
        ->where(function ($query) use ($startDate, $endDate) {
            $query->where('starts_at', '<=', $endDate)
            ->where('ends_at', '>=', $startDate);
        })
        ->where('payment_completed', true)
        ->exists();
    }

    /**
     * Get all active advertisements for display.
     *
     * @return Collection<int, self>
     */
    public static function getActiveAdvertisements(): Collection
    {
        $now = now();

        return static::query()
        ->with(['product', 'product.user'])
        ->where('payment_completed', true)
        ->where('starts_at', '<=', $now)
        ->where('ends_at', '>=', $now)
        ->orderBy('slot_number')
        ->get();
    }

    /**
     * Check if the payment has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    /**
     * Check if the advertisement period has started.
     */
    public function hasStarted(): bool
    {
        return $this->starts_at?->isPast() ?? false;
    }

    /**
     * Check if the advertisement period has ended.
     */
    public function hasEnded(): bool
    {
        return $this->ends_at?->isPast() ?? false;
    }

    /**
     * Check if the advertisement is currently active.
     */
    public function isActive(): bool
    {
        $now = now();

        return $this->payment_completed
        && $this->starts_at !== null
        && $this->ends_at !== null
        && $this->starts_at <= $now
        && $this->ends_at >= $now;
    }

    /**
     * Get the product associated with the advertisement.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created the advertisement.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if a vendor has reached their daily advertisement request limit.
     */
    public static function hasReachedDailyLimit(int|string $userId): bool
    {
        $last24Hours = now()->subHours(24);

        $requestCount = static::query()
        ->where('user_id', (int) $userId)
        ->where('created_at', '>=', $last24Hours)
        ->count();

        return $requestCount >= 8;
    }

    /**
     * Get the cooldown end time for a vendor who has reached their limit.
     */
    public static function getCooldownEndTime(int|string $userId): ?Carbon
    {
        $oldestRequest = static::query()
        ->where('user_id', (int) $userId)
        ->where('created_at', '>=', now()->subHours(24))
        ->orderBy('created_at')
        ->first();

        return $oldestRequest?->created_at?->copy()->addHours(24);
    }

    /**
     * Check if a product is currently being advertised.
     */
    public static function isProductAdvertised(mixed $productId): bool
    {
        return static::query()
        ->where('product_id', $productId)
        ->where('payment_completed', true)
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->exists();
    }
}
