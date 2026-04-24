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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Orders extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_WAITING_PAYMENT = 'waiting_payment';
    public const STATUS_PAYMENT_RECEIVED = 'payment_received';
    public const STATUS_PRODUCT_SENT = 'product_sent';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DISPUTED = 'disputed';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'vendor_id',
        'unique_url',
        'subtotal',
        'commission',
        'total',
        'status',
        'shipping_address',
        'delivery_option',
        'encrypted_message',
        'is_paid',
        'is_sent',
        'is_completed',
        'is_disputed',
        'paid_at',
        'sent_at',
        'completed_at',
        'disputed_at',
        'payment_address',
        'payment_address_index',
        'required_xmr_amount',
        'total_received_xmr',
        'xmr_usd_rate',
        'expires_at',
        'payment_completed_at',
        'vendor_payment_amount',
        'vendor_payment_address',
        'vendor_payment_at',
        'buyer_refund_amount',
        'buyer_refund_address',
        'buyer_refund_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'commission' => 'decimal:2',
        'total' => 'decimal:2',
        'required_xmr_amount' => 'decimal:12',
        'total_received_xmr' => 'decimal:12',
        'vendor_payment_amount' => 'decimal:12',
        'buyer_refund_amount' => 'decimal:12',
        'xmr_usd_rate' => 'decimal:2',
        'is_paid' => 'boolean',
        'is_sent' => 'boolean',
        'is_completed' => 'boolean',
        'is_disputed' => 'boolean',
        'paid_at' => 'datetime',
        'sent_at' => 'datetime',
        'completed_at' => 'datetime',
        'disputed_at' => 'datetime',
        'expires_at' => 'datetime',
        'payment_completed_at' => 'datetime',
        'vendor_payment_at' => 'datetime',
        'buyer_refund_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->unique_url)) {
                $model->unique_url = Str::random(30);
            }

            if (empty($model->status)) {
                $model->status = self::STATUS_WAITING_PAYMENT;
            }
        });
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'unique_url';
    }

    /**
     * Get the user (buyer) that owns the order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the vendor for this order.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    /**
     * Get the items for this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Get the dispute for this order.
     */
    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class, 'order_id');
    }

    public function generatePaymentAddress($walletRPC): bool
    {
        try {
            if (empty($this->payment_address)) {
                $result = $walletRPC->create_address(0, 'Order Payment '.$this->id);

                $this->payment_address = $result['address'];
                $this->payment_address_index = $result['address_index'];
                $this->expires_at = now()->addMinutes((int) config('monero.address_expiration_time', 1440));
                $this->save();
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to generate payment address: '.$e->getMessage());
            return false;
        }
    }

    public function checkPayments($walletRPC): bool
    {
        if ($this->is_paid || $this->status !== self::STATUS_WAITING_PAYMENT) {
            return false;
        }

        try {
            $transfers = $walletRPC->get_transfers([
                'in' => true,
                'pool' => true,
                'subaddr_indices' => [$this->payment_address_index],
            ]);

            $minAcceptedAmount = $this->required_xmr_amount * 0.10;

            $totalReceived = 0;
            foreach (['in', 'pool'] as $type) {
                if (isset($transfers[$type])) {
                    foreach ($transfers[$type] as $transfer) {
                        $amount = $transfer['amount'] / 1e12;
                        if ($amount >= $minAcceptedAmount) {
                            $totalReceived += $amount;
                        }
                    }
                }
            }

            $this->total_received_xmr = $totalReceived;

            if ($totalReceived >= $this->required_xmr_amount && ! $this->is_paid) {
                $this->status = self::STATUS_PAYMENT_RECEIVED;
                $this->is_paid = true;
                $this->paid_at = now();
                $this->payment_completed_at = now();
            }

            $this->save();
            return true;
        } catch (\Exception $e) {
            Log::error('Error checking order payments: '.$e->getMessage());
            return false;
        }
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function handleExpiredPayment(): bool
    {
        if ($this->status !== self::STATUS_WAITING_PAYMENT || ! $this->isExpired()) {
            return false;
        }

        return $this->markAsCancelled();
    }

    public function shouldAutoCancelIfNotSent(): bool
    {
        if ($this->status !== self::STATUS_PAYMENT_RECEIVED) {
            return false;
        }

        if (! $this->paid_at || $this->paid_at->copy()->addHours(96)->isFuture()) {
            return false;
        }

        return true;
    }

    public function autoCancelIfNotSent(): bool
    {
        if (! $this->shouldAutoCancelIfNotSent()) {
            return false;
        }

        return $this->markAsCancelled();
    }

    public function shouldAutoCompleteIfNotConfirmed(): bool
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT) {
            return false;
        }

        if ($this->is_disputed || $this->status === self::STATUS_DISPUTED) {
            return false;
        }

        if (! $this->sent_at || $this->sent_at->copy()->addHours(192)->isFuture()) {
            return false;
        }

        return true;
    }

    public function autoCompleteIfNotConfirmed(): bool
    {
        if (! $this->shouldAutoCompleteIfNotConfirmed()) {
            return false;
        }

        return $this->markAsCompleted();
    }

    public function getAutoCancelDeadline()
    {
        if ($this->status !== self::STATUS_PAYMENT_RECEIVED || ! $this->paid_at) {
            return null;
        }

        return $this->paid_at->copy()->addHours(96);
    }

    public function getAutoCompleteDeadline()
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT || ! $this->sent_at || $this->is_disputed) {
            return null;
        }

        return $this->sent_at->copy()->addHours(192);
    }

    public static function findOrdersToAutoCancel()
    {
        return self::query()
        ->where('status', self::STATUS_PAYMENT_RECEIVED)
        ->whereNotNull('paid_at')
        ->where('paid_at', '<=', now()->subHours(96))
        ->get();
    }

    public static function findOrdersToAutoComplete()
    {
        return self::query()
        ->where('status', self::STATUS_PRODUCT_SENT)
        ->where('is_disputed', false)
        ->whereNotNull('sent_at')
        ->where('sent_at', '<=', now()->subHours(192))
        ->get();
    }

    public static function processAllAutoStatusChanges(): array
    {
        $cancelCount = 0;
        $completeCount = 0;

        foreach (self::findOrdersToAutoCancel() as $order) {
            if ($order->autoCancelIfNotSent()) {
                $cancelCount++;
            }
        }

        foreach (self::findOrdersToAutoComplete() as $order) {
            if ($order->autoCompleteIfNotConfirmed()) {
                $completeCount++;
            }
        }

        return [
            'cancelled' => $cancelCount,
            'completed' => $completeCount,
        ];
    }

    public function calculateRequiredXmrAmount($xmrUsdRate): float
    {
        if ($xmrUsdRate <= 0) {
            throw new \InvalidArgumentException('XMR rate must be greater than zero');
        }

        return $this->total / $xmrUsdRate;
    }

    public function markAsPaid(): bool
    {
        if ($this->status !== self::STATUS_WAITING_PAYMENT) {
            return false;
        }

        $this->status = self::STATUS_PAYMENT_RECEIVED;
        $this->is_paid = true;
        $this->paid_at = now();
        $this->save();

        return true;
    }

    public function markAsSent(): bool
    {
        if ($this->status !== self::STATUS_PAYMENT_RECEIVED) {
            return false;
        }

        $this->status = self::STATUS_PRODUCT_SENT;
        $this->is_sent = true;
        $this->sent_at = now();
        $this->save();

        return true;
    }

    public function markAsCompleted(): bool
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT && $this->status !== self::STATUS_DISPUTED) {
            return false;
        }

        $currentStatus = $this->status;

        $this->status = self::STATUS_COMPLETED;
        $this->is_completed = true;
        $this->completed_at = now();

        if ($currentStatus === self::STATUS_DISPUTED) {
            $this->is_disputed = false;
        }

        $this->save();

        foreach ($this->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $actualQuantity = $item->quantity;
            if ($item->bulk_option && isset($item->bulk_option['amount'])) {
                $actualQuantity = $item->quantity * $item->bulk_option['amount'];
            }

            if ($product->stock_amount >= $actualQuantity) {
                $product->stock_amount -= $actualQuantity;
                $product->save();
            }
        }

        $this->processVendorPayment();

        return true;
    }

    public function processVendorPayment(): bool
    {
        if (! $this->total_received_xmr || $this->total_received_xmr <= 0) {
            Log::error("Order {$this->id} has no received XMR to pay vendor");
            return false;
        }

        try {
            $commissionRatio = $this->subtotal > 0 ? $this->commission / $this->subtotal : 0;
            $vendorPaymentAmount = $this->total_received_xmr * (1 - $commissionRatio);

            $vendor = $this->vendor;
            if (! $vendor) {
                Log::error("Order {$this->id} has no associated vendor");
                return false;
            }

            $returnAddress = $vendor->returnAddresses()->inRandomOrder()->first();
            if (! $returnAddress) {
                Log::error("Vendor {$vendor->id} has no return addresses for payment");
                return false;
            }

            $config = config('monero');
            $walletRPC = new \MoneroIntegrations\MoneroPhp\walletRPC(
                $config['host'],
                $config['port'],
                $config['ssl'],
                30000
            );

            $walletRPC->transfer([
                'address' => $returnAddress->monero_address,
                'amount' => $vendorPaymentAmount,
                'priority' => 1,
            ]);

            Log::info("Vendor payment processed: Order {$this->id}, Amount: {$vendorPaymentAmount} XMR, Address: {$returnAddress->monero_address}");

            $this->vendor_payment_amount = $vendorPaymentAmount;
            $this->vendor_payment_address = $returnAddress->monero_address;
            $this->vendor_payment_at = now();
            $this->save();

            return true;
        } catch (\Exception $e) {
            Log::error("Error processing vendor payment for order {$this->id}: ".$e->getMessage());
            return false;
        }
    }

    public function processBuyerRefund(): bool
    {
        if (! $this->total_received_xmr || $this->total_received_xmr <= 0) {
            Log::info("Order {$this->id} has no received XMR to refund to buyer");
            return false;
        }

        try {
            $commissionPercentage = config('monero.cancelled_order_commission_percentage', 1.0);
            $buyerRefundAmount = $this->total_received_xmr * (1 - ($commissionPercentage / 100));

            $buyer = $this->user;
            if (! $buyer) {
                Log::error("Order {$this->id} has no associated buyer");
                return false;
            }

            $returnAddress = $buyer->returnAddresses()->inRandomOrder()->first();
            if (! $returnAddress) {
                Log::error("Buyer {$buyer->id} has no return addresses for refund");
                return false;
            }

            $config = config('monero');
            $walletRPC = new \MoneroIntegrations\MoneroPhp\walletRPC(
                $config['host'],
                $config['port'],
                $config['ssl'],
                30000
            );

            $walletRPC->transfer([
                'address' => $returnAddress->monero_address,
                'amount' => $buyerRefundAmount,
                'priority' => 1,
            ]);

            Log::info("Buyer refund processed: Order {$this->id}, Amount: {$buyerRefundAmount} XMR, Address: {$returnAddress->monero_address}");

            $this->buyer_refund_amount = $buyerRefundAmount;
            $this->buyer_refund_address = $returnAddress->monero_address;
            $this->buyer_refund_at = now();
            $this->save();

            return true;
        } catch (\Exception $e) {
            Log::error("Error processing buyer refund for order {$this->id}: ".$e->getMessage());
            return false;
        }
    }

    public function markAsCancelled(): bool
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return false;
        }

        $currentStatus = $this->status;

        $this->status = self::STATUS_CANCELLED;

        if ($currentStatus === self::STATUS_DISPUTED) {
            $this->is_disputed = false;
        }

        $this->save();

        if ($currentStatus === self::STATUS_PAYMENT_RECEIVED || $currentStatus === self::STATUS_PRODUCT_SENT || $currentStatus === self::STATUS_DISPUTED) {
            $this->processBuyerRefund();
        }

        return true;
    }

    public function getFormattedStatus(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING_PAYMENT => 'Waiting for Payment',
            self::STATUS_PAYMENT_RECEIVED => 'Payment Received',
            self::STATUS_PRODUCT_SENT => 'Product Sent',
            self::STATUS_COMPLETED => 'Order Completed',
            self::STATUS_CANCELLED => 'Order Cancelled',
            self::STATUS_DISPUTED => 'Order Disputed',
            default => 'Unknown Status',
        };
    }

    public function openDispute($reason)
    {
        if ($this->status !== self::STATUS_PRODUCT_SENT) {
            return false;
        }

        $this->status = self::STATUS_DISPUTED;
        $this->is_disputed = true;
        $this->disputed_at = now();
        $this->save();

        $dispute = new Dispute([
            'order_id' => $this->id,
            'status' => Dispute::STATUS_ACTIVE,
            'reason' => $reason,
        ]);
        $dispute->save();

        return $dispute;
    }

    public function hasActiveDispute()
    {
        return $this->dispute && $this->dispute->status === Dispute::STATUS_ACTIVE;
    }

    public static function getUserOrders($userId)
    {
        return self::query()
        ->where('user_id', $userId)
        ->with(['items', 'vendor'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    public static function getVendorOrders($vendorId)
    {
        return self::query()
        ->where('vendor_id', $vendorId)
        ->with(['items', 'user'])
        ->orderBy('created_at', 'desc')
        ->get();
    }

    public static function findByUrl($url)
    {
        return self::query()
        ->where('unique_url', $url)
        ->with(['items', 'user', 'vendor'])
        ->first();
    }

    public static function hasExcessiveCancelledOrders($userId, $vendorId, $limit = 4): bool
    {
        $cancelledCount = self::query()
        ->where('user_id', $userId)
        ->where('vendor_id', $vendorId)
        ->where('status', self::STATUS_CANCELLED)
        ->count();

        return $cancelledCount >= $limit;
    }

    public static function hasPendingPaymentOrder($userId, $vendorId): bool
    {
        return self::query()
        ->where('user_id', $userId)
        ->where('vendor_id', $vendorId)
        ->where('status', self::STATUS_WAITING_PAYMENT)
        ->exists();
    }

    public static function canCreateNewOrder($userId, $vendorId): array
    {
        if (self::hasExcessiveCancelledOrders($userId, $vendorId)) {
            return [false, 'You have too many cancelled orders with this vendor. Please contact support.'];
        }

        if (self::hasPendingPaymentOrder($userId, $vendorId)) {
            return [false, 'You already have a pending payment order with this vendor. Please complete or cancel it before creating a new order.'];
        }

        return [true, ''];
    }

    public static function createFromCart($user, $cartItems, $subtotal, $commission, $total)
    {
        $vendorId = $cartItems->first()->product->user_id;

        $order = self::create([
            'user_id' => $user->id,
            'vendor_id' => $vendorId,
            'subtotal' => $subtotal,
            'commission' => $commission,
            'total' => $total,
            'status' => self::STATUS_WAITING_PAYMENT,
        ]);

        foreach ($cartItems as $cartItem) {
            $product = $cartItem->product;

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_description' => $product->description,
                'price' => $cartItem->price,
                'quantity' => $cartItem->quantity,
                'measurement_unit' => $product->measurement_unit,
                'delivery_option' => $cartItem->selected_delivery_option,
                'bulk_option' => $cartItem->selected_bulk_option,
            ]);

            if ($cartItem->encrypted_message) {
                $order->encrypted_message = $cartItem->encrypted_message;
                $order->save();
            }
        }

        return $order;
    }
}
