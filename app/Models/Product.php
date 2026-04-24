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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_DIGITAL = 'digital';
    public const TYPE_CARGO = 'cargo';
    public const TYPE_DEADDROP = 'deaddrop';

    public const UNIT_GRAM = 'g';
    public const UNIT_KILOGRAM = 'kg';
    public const UNIT_MILLILITER = 'ml';
    public const UNIT_LITER = 'l';
    public const UNIT_CENTIMETER = 'cm';
    public const UNIT_METER = 'm';
    public const UNIT_INCH = 'in';
    public const UNIT_FOOT = 'ft';
    public const UNIT_SQUARE_METER = 'm²';
    public const UNIT_PIECE = 'piece';
    public const UNIT_DOZEN = 'dozen';
    public const UNIT_HOUR = 'hour';
    public const UNIT_DAY = 'day';
    public const UNIT_MONTH = 'month';

    /**
     * Get all available measurement units.
     *
     * @return array<string, string>
     */
    public static function getMeasurementUnits(): array
    {
        return [
            self::UNIT_GRAM => 'Grams (g)',
            self::UNIT_KILOGRAM => 'Kilograms (kg)',
            self::UNIT_MILLILITER => 'Milliliters (ml)',
            self::UNIT_LITER => 'Liters (l)',
            self::UNIT_CENTIMETER => 'Centimeters (cm)',
            self::UNIT_METER => 'Meters (m)',
            self::UNIT_INCH => 'Inches (in)',
            self::UNIT_FOOT => 'Feet (ft)',
            self::UNIT_SQUARE_METER => 'Square Meters (m²)',
            self::UNIT_PIECE => 'Units (pieces)',
            self::UNIT_DOZEN => 'Dozens (12 items)',
            self::UNIT_HOUR => 'Hours',
            self::UNIT_DAY => 'Days',
            self::UNIT_MONTH => 'Months',
        ];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'type',
        'active',
        'user_id',
        'category_id',
        'slug',
        'product_picture',
        'stock_amount',
        'measurement_unit',
        'delivery_options',
        'bulk_options',
        'ships_from',
        'ships_to',
        'additional_photos',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
        'delivery_options' => 'array',
        'bulk_options' => 'array',
        'additional_photos' => 'array',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'product_picture_url',
        'additional_photos_urls',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->slug)) {
                $model->slug = str()->random(80);
            }

            if (empty($model->product_picture)) {
                $model->product_picture = 'default-product-picture.png';
            }

            if (! isset($model->delivery_options)) {
                $model->delivery_options = [];
            }
        });
    }

    /**
     * Validate delivery options structure.
     */
    public function validateDeliveryOptions(array $options): bool
    {
        if (count($options) < 1 || count($options) > 4) {
            return false;
        }

        foreach ($options as $option) {
            if (! isset($option['description'], $option['price'])) {
                return false;
            }

            if (! is_string($option['description']) || trim($option['description']) === '') {
                return false;
            }

            if (! is_numeric($option['price']) || $option['price'] < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate bulk options structure.
     */
    public function validateBulkOptions(?array $options): bool
    {
        if ($options === null || empty($options)) {
            return true;
        }

        if (count($options) > 4) {
            return false;
        }

        foreach ($options as $option) {
            if (! isset($option['amount'], $option['price'])) {
                return false;
            }

            if (! is_numeric($option['amount']) || $option['amount'] <= 0) {
                return false;
            }

            if (! is_numeric($option['price']) || $option['price'] <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get formatted delivery options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFormattedDeliveryOptions(float|string|null $xmrPrice = null): array
    {
        return array_map(function (array $option) use ($xmrPrice): array {
            $optionXmrPrice = is_numeric($xmrPrice) && $xmrPrice > 0
            ? $option['price'] / $xmrPrice
            : null;

            $totalXmrPrice = is_numeric($xmrPrice) && $xmrPrice > 0
            ? ($this->price + $option['price']) / $xmrPrice
            : null;

            $priceDisplay = '$'.number_format($option['price'], 2);

            if ($optionXmrPrice !== null) {
                $priceDisplay .= sprintf(' (≈ ɱ%s)', number_format($optionXmrPrice, 4));
            }

            $totalPriceDisplay = '$'.number_format($this->price + $option['price'], 2);

            if ($totalXmrPrice !== null) {
                $totalPriceDisplay .= sprintf(' (≈ ɱ%s)', number_format($totalXmrPrice, 4));
            }

            return [
                'description' => $option['description'],
                'price' => $priceDisplay,
                'total_price' => $totalPriceDisplay,
            ];
        }, $this->delivery_options ?? []);
    }

    /**
     * Get formatted bulk options.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFormattedBulkOptions(float|string|null $xmrPrice = null): array
    {
        $measurementUnits = self::getMeasurementUnits();
        $formattedUnit = $measurementUnits[$this->measurement_unit] ?? $this->measurement_unit;

        return array_map(function (array $option) use ($xmrPrice, $formattedUnit): array {
            $xmrAmount = is_numeric($xmrPrice) && $xmrPrice > 0
            ? $option['price'] / $xmrPrice
            : null;

            return [
                'amount' => $option['amount'],
                'price' => number_format($option['price'], 2),
                         'display_text' => sprintf(
                             '%s %s for $%s%s',
                             number_format($option['amount']),
                                                   $formattedUnit,
                                                   number_format($option['price'], 2),
                                                   $xmrAmount !== null ? sprintf(' (≈ ɱ%s)', number_format($xmrAmount, 4)) : ''
                         ),
            ];
        }, $this->bulk_options ?? []);
    }

    /**
     * Get the additional photos URLs.
     *
     * @return array<int, string>
     */
    public function getAdditionalPhotosUrlsAttribute(): array
    {
        if (empty($this->additional_photos)) {
            return [];
        }

        return array_map(function (string $photo): string {
            if ($photo === 'default-product-picture.png') {
                return asset('images/default-product-picture.png');
            }

            return route('product.picture', $photo);
        }, $this->additional_photos);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the product picture URL.
     */
    public function getProductPictureUrlAttribute(): string
    {
        if ($this->product_picture === 'default-product-picture.png') {
            return asset('images/default-product-picture.png');
        }

        return route('product.picture', $this->product_picture);
    }

    /**
     * Get the user that owns the product.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the category that the product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the users who have wishlisted this product.
     */
    public function wishlistedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlists')
        ->withTimestamps()
        ->orderBy('wishlists.created_at', 'desc');
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to only include products of a specific type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Check if the product is digital.
     */
    public function isDigital(): bool
    {
        return $this->type === self::TYPE_DIGITAL;
    }

    /**
     * Check if the product is cargo.
     */
    public function isCargo(): bool
    {
        return $this->type === self::TYPE_CARGO;
    }

    /**
     * Check if the product is deaddrop.
     */
    public function isDeadDrop(): bool
    {
        return $this->type === self::TYPE_DEADDROP;
    }

    /**
     * Create a new digital product instance.
     */
    public static function createDigital(array $attributes): self
    {
        return static::create(array_merge($attributes, ['type' => self::TYPE_DIGITAL]));
    }

    /**
     * Create a new cargo product instance.
     */
    public static function createCargo(array $attributes): self
    {
        return static::create(array_merge($attributes, ['type' => self::TYPE_CARGO]));
    }

    /**
     * Create a new deaddrop product instance.
     */
    public static function createDeadDrop(array $attributes): self
    {
        return static::create(array_merge($attributes, ['type' => self::TYPE_DEADDROP]));
    }

    /**
     * Get products of a specific type.
     */
    public static function getByType(string $type): Builder
    {
        return static::query()->where('type', $type);
    }

    /**
     * Get all digital products.
     */
    public static function getDigitalProducts(): Builder
    {
        return static::getByType(self::TYPE_DIGITAL);
    }

    /**
     * Get all cargo products.
     */
    public static function getCargoProducts(): Builder
    {
        return static::getByType(self::TYPE_CARGO);
    }

    /**
     * Get all deaddrop products.
     */
    public static function getDeadDropProducts(): Builder
    {
        return static::getByType(self::TYPE_DEADDROP);
    }

    /**
     * Get the reviews for this product.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReviews::class);
    }

    /**
     * Get the featured status for this product.
     */
    public function featuredProduct(): HasOne
    {
        return $this->hasOne(FeaturedProduct::class);
    }

    /**
     * Check if the product is featured.
     */
    public function isFeatured(): bool
    {
        return $this->featuredProduct()->exists();
    }

    /**
     * Get the percentage of positive reviews for this product.
     */
    public function getPositiveReviewPercentage(): ?float
    {
        $totalReviews = $this->reviews()->count();

        if ($totalReviews === 0) {
            return null;
        }

        $positiveReviews = $this->reviews()
        ->where('sentiment', ProductReviews::SENTIMENT_POSITIVE)
        ->count();

        return ($positiveReviews / $totalReviews) * 100;
    }

    /**
     * Get the count of positive reviews for this product.
     */
    public function getPositiveReviewsCount(): int
    {
        return $this->reviews()
        ->where('sentiment', ProductReviews::SENTIMENT_POSITIVE)
        ->count();
    }

    /**
     * Get the count of mixed reviews for this product.
     */
    public function getMixedReviewsCount(): int
    {
        return $this->reviews()
        ->where('sentiment', ProductReviews::SENTIMENT_MIXED)
        ->count();
    }

    /**
     * Get the count of negative reviews for this product.
     */
    public function getNegativeReviewsCount(): int
    {
        return $this->reviews()
        ->where('sentiment', ProductReviews::SENTIMENT_NEGATIVE)
        ->count();
    }
}
