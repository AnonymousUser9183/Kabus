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
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReviews extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const SENTIMENT_POSITIVE = 'positive';
    public const SENTIMENT_MIXED = 'mixed';
    public const SENTIMENT_NEGATIVE = 'negative';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'order_id',
        'order_item_id',
        'review_text',
        'sentiment',
    ];

    /**
     * Get all available sentiment options.
     *
     * @return array<string, string>
     */
    public static function getSentimentOptions(): array
    {
        return [
            self::SENTIMENT_POSITIVE => 'Positive',
            self::SENTIMENT_MIXED => 'Mixed',
            self::SENTIMENT_NEGATIVE => 'Negative',
        ];
    }

    /**
     * Get the user that owns the review.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product that was reviewed.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the order associated with this review.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Orders::class, 'order_id');
    }

    /**
     * Get the order item associated with this review.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Check if the review is positive.
     */
    public function isPositive(): bool
    {
        return $this->sentiment === self::SENTIMENT_POSITIVE;
    }

    /**
     * Check if the review is mixed.
     */
    public function isMixed(): bool
    {
        return $this->sentiment === self::SENTIMENT_MIXED;
    }

    /**
     * Check if the review is negative.
     */
    public function isNegative(): bool
    {
        return $this->sentiment === self::SENTIMENT_NEGATIVE;
    }

    /**
     * Get all reviews for a product.
     *
     * @return Collection<int, self>
     */
    public static function getProductReviews(string $productId): Collection
    {
        return self::where('product_id', $productId)
        ->with(['user:id,username'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    /**
     * Get the formatted created at date.
     */
    public function getFormattedDate(): string
    {
        return $this->created_at->format('Y-m-d');
    }
}
