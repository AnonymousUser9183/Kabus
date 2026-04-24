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

use gnupg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Cart extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'cart';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
        'price',
        'selected_delivery_option',
        'selected_bulk_option',
        'encrypted_message',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'selected_delivery_option' => 'array',
        'selected_bulk_option' => 'array',
        'encrypted_message' => 'string',
    ];

    /**
     * Get the user that owns the cart item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the active product in the cart.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the product in the cart, including soft-deleted products.
     */
    public function productWithTrashed(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Check if the cart item references a soft-deleted product.
     */
    public function hasDeletedProduct(): bool
    {
        return $this->product === null && $this->productWithTrashed()->first() !== null;
    }

    /**
     * Encrypt a message using the vendor's PGP key.
     *
     * @return string|false
     */
    public function encryptMessageForVendor(string $message)
    {
        try {
            $product = $this->productWithTrashed()->first();
            $vendorPgpKey = $product?->user?->pgpKey;

            if (! $vendorPgpKey || ! $vendorPgpKey->public_key) {
                return false;
            }

            putenv('GNUPGHOME=/tmp');

            $gpg = new gnupg();
            $gpg->seterrormode(gnupg::ERROR_EXCEPTION);

            $info = $gpg->import($vendorPgpKey->public_key);

            if (! $info || empty($info['fingerprint'])) {
                return false;
            }

            $gpg->addencryptkey($info['fingerprint']);

            return $gpg->encrypt($message);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Calculate total price for this cart item including delivery option.
     */
    public function getTotalPrice(): float
    {
        $basePrice = $this->price * $this->quantity;

        return $basePrice + ($this->selected_delivery_option['price'] ?? 0);
    }

    /**
     * Validate if a product can be added to user's cart.
     *
     * @return array{valid: bool, reason: string}
     */
    public static function validateProductAddition(User $user, Product $product): array
    {
        $existingItem = self::query()
        ->where('user_id', $user->id)
        ->first();

        if ($existingItem) {
            $existingProduct = $existingItem->productWithTrashed()->first();
            $existingVendorId = $existingProduct?->user_id;

            if ($existingVendorId && $product->user_id !== $existingVendorId) {
                return [
                    'valid' => false,
                    'reason' => 'different_vendor',
                ];
            }
        }

        if (! $product->active) {
            return [
                'valid' => false,
                'reason' => 'inactive',
            ];
        }

        if ($product->user->vendorProfile && $product->user->vendorProfile->vacation_mode) {
            return [
                'valid' => false,
                'reason' => 'vacation',
            ];
        }

        if ($product->stock_amount < 1) {
            return [
                'valid' => false,
                'reason' => 'out_of_stock',
            ];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Validate if requested quantity is available in stock.
     *
     * @return array<string, mixed>
     */
    public static function validateStockAvailability(Product $product, int $quantity, ?array $bulkOption = null): array
    {
        $totalStockNeeded = $bulkOption
        ? $quantity * $bulkOption['amount']
        : $quantity;

        if ($totalStockNeeded > $product->stock_amount) {
            return [
                'valid' => false,
                'reason' => 'insufficient_stock',
                'available' => $product->stock_amount,
                'requested' => $totalStockNeeded,
            ];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Get the total price for all items in a user's cart.
     */
    public static function getCartTotal(User $user): float
    {
        return self::query()
        ->where('user_id', $user->id)
        ->get()
        ->sum(function (self $item): float {
            return $item->getTotalPrice();
        });
    }
}
