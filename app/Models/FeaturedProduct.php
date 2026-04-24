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

class FeaturedProduct extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id',
        'admin_id',
    ];

    /**
     * Get the product that is featured.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the admin who featured the product.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Get all featured products with their associated data in random order.
     *
     * @return Collection<int, self>
     */
    public static function getAllFeaturedProducts(): Collection
    {
        return static::query()
        ->with(['product', 'product.user'])
        ->inRandomOrder()
        ->get();
    }

    /**
     * Check if a product is currently featured.
     */
    public static function isProductFeatured(string $productId): bool
    {
        return static::query()
        ->where('product_id', $productId)
        ->exists();
    }
}
