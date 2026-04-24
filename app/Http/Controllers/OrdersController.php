<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Orders;
use App\Models\ProductReviews;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MoneroIntegrations\MoneroPhp\walletRPC;

class OrdersController extends Controller
{
    protected ?walletRPC $walletRPC = null;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $config = config('monero');

        try {
            $this->walletRPC = new walletRPC(
                $config['host'],
                $config['port'],
                $config['ssl']
            );
        } catch (Exception $exception) {
            Log::error('Failed to initialize Monero RPC connection: '.$exception->getMessage());
            $this->walletRPC = null;
        }
    }

    /**
     * Display a listing of the user's orders.
     */
    public function index()
    {
        $orders = Orders::getUserOrders(Auth::id());

        return view('orders.index', [
            'orders' => $orders,
        ]);
    }

    /**
     * Display the specified order.
     */
    public function show(string $uniqueUrl): RedirectResponse|\Illuminate\View\View
    {
        Orders::processAllAutoStatusChanges();

        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->user_id !== Auth::id() && $order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access.');
        }

        $isBuyer = $order->user_id === Auth::id();
        $qrCode = null;
        $dispute = $order->dispute;

        if ($isBuyer && $order->status === Orders::STATUS_WAITING_PAYMENT) {
            if ($order->isExpired() && ! empty($order->payment_address)) {
                $order->handleExpiredPayment();
                $order->refresh();

                if ($order->status === Orders::STATUS_CANCELLED) {
                    return redirect()
                    ->route('orders.show', $order->unique_url)
                    ->with('info', 'This order has been automatically cancelled because the payment window has expired.');
                }
            }

            if ($order->shouldAutoCancelIfNotSent()) {
                $order->autoCancelIfNotSent();
                $order->refresh();

                if ($order->status === Orders::STATUS_CANCELLED) {
                    return redirect()
                    ->route('orders.show', $order->unique_url)
                    ->with('info', 'This order has been automatically cancelled because the vendor did not mark it as sent within 96 hours (4 days) after payment.');
                }
            }

            if ($order->shouldAutoCompleteIfNotConfirmed()) {
                $order->autoCompleteIfNotConfirmed();
                $order->refresh();

                if ($order->status === Orders::STATUS_COMPLETED) {
                    return redirect()
                    ->route('orders.show', $order->unique_url)
                    ->with('info', 'This order has been automatically marked as completed because it was not confirmed within 192 hours (8 days) after being marked as sent.');
                }
            }

            if (empty($order->payment_address) && $order->status === Orders::STATUS_WAITING_PAYMENT) {
                try {
                    if (! $this->walletRPC) {
                        return redirect()
                        ->back()
                        ->with('error', 'Payment service is currently unavailable. Please try again later.');
                    }

                    $xmrPriceController = new XmrPriceController();
                    $xmrRate = $xmrPriceController->getXmrPrice();

                    if ($xmrRate === 'UNAVAILABLE') {
                        return redirect()
                        ->back()
                        ->with('error', 'Unable to get XMR price. Please try again later.');
                    }

                    $requiredXmrAmount = $order->calculateRequiredXmrAmount($xmrRate);

                    $order->required_xmr_amount = $requiredXmrAmount;
                    $order->xmr_usd_rate = $xmrRate;
                    $order->save();

                    if (! $order->generatePaymentAddress($this->walletRPC)) {
                        return redirect()
                        ->back()
                        ->with('error', 'Unable to generate payment address. Please try again.');
                    }
                } catch (Exception $exception) {
                    Log::error('Error setting up payment: '.$exception->getMessage());

                    return redirect()
                    ->back()
                    ->with('error', 'Error setting up payment: '.$exception->getMessage());
                }
            }

            try {
                if ($this->walletRPC) {
                    $order->checkPayments($this->walletRPC);
                }
            } catch (Exception $exception) {
                Log::error('Error checking payments: '.$exception->getMessage());
            }

            $order->refresh();

            if (! $order->is_paid && $order->payment_address) {
                try {
                    $qrCode = $this->generateQrCode($order->payment_address);
                } catch (Exception $exception) {
                    Log::error('Error generating QR code: '.$exception->getMessage());
                }
            }
        }

        if ($isBuyer && $order->status === Orders::STATUS_COMPLETED) {
            foreach ($order->items as $item) {
                $item->existingReview = ProductReviews::where('user_id', Auth::id())
                ->where('order_item_id', $item->id)
                ->first();
            }
        }

        $totalItems = 0;

        foreach ($order->items as $item) {
            if ($item->bulk_option && isset($item->bulk_option['amount'])) {
                $totalItems += $item->quantity * $item->bulk_option['amount'];
            } else {
                $totalItems += $item->quantity;
            }
        }

        return view('orders.show', [
            'order' => $order,
            'isBuyer' => $isBuyer,
            'dispute' => $dispute,
            'qrCode' => $qrCode,
            'totalItems' => $totalItems,
        ]);
    }

    /**
     * Create a new order from the cart items.
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $user = Auth::user();
            $cartItems = Cart::where('user_id', $user->id)
            ->with(['product', 'product.user'])
            ->get();

            if ($cartItems->isEmpty()) {
                return redirect()
                ->route('cart.index')
                ->with('error', 'Your cart is empty.');
            }

            $vendorId = $cartItems->first()->product->user_id;

            [$canCreate, $reason] = Orders::canCreateNewOrder($user->id, $vendorId);

            if (! $canCreate) {
                return redirect()
                ->route('cart.checkout')
                ->with('error', $reason);
            }

            $subtotal = Cart::getCartTotal($user);
            $commissionPercentage = config('marketplace.commission_percentage');
            $commission = ($subtotal * $commissionPercentage) / 100;
            $total = $subtotal + $commission;

            $order = Orders::createFromCart($user, $cartItems, $subtotal, $commission, $total);

            Cart::where('user_id', $user->id)->delete();

            return redirect()
            ->route('orders.show', $order->unique_url)
            ->with('success', 'Order created successfully. Please complete the payment.');
        } catch (Exception $exception) {
            Log::error('Failed to create order: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->route('cart.checkout')
            ->with('error', 'Failed to create order. Please try again.');
        }
    }

    /**
     * Generate a QR code for the given address.
     */
    private function generateQrCode(string $address): ?string
    {
        try {
            $result = Builder::create()
            ->writer(new PngWriter())
            ->writerOptions([])
            ->data($address)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(300)
            ->margin(10)
            ->build();

            return $result->getDataUri();
        } catch (Exception $exception) {
            Log::error('Error generating QR code: '.$exception->getMessage());

            return null;
        }
    }

    /**
     * Mark the order as sent.
     */
    public function markAsSent(string $uniqueUrl): RedirectResponse
    {
        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->markAsSent()) {
            return redirect()
            ->route('vendor.sales.show', $order->unique_url)
            ->with('success', 'Product marked as sent. The buyer has been notified.');
        }

        return redirect()
        ->route('vendor.sales.show', $order->unique_url)
        ->with('error', 'Unable to mark as sent at this time.');
    }

    /**
     * Mark the order as completed.
     */
    public function markAsCompleted(string $uniqueUrl): RedirectResponse
    {
        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->markAsCompleted()) {
            return redirect()
            ->route('orders.show', $order->unique_url)
            ->with('success', 'Order marked as completed and payment has been sent to the vendor. Thank you for your purchase.');
        }

        return redirect()
        ->route('orders.show', $order->unique_url)
        ->with('error', 'Unable to mark as completed at this time.');
    }

    /**
     * Mark the order as cancelled.
     */
    public function markAsCancelled(string $uniqueUrl): RedirectResponse
    {
        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->user_id !== Auth::id() && $order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->status === Orders::STATUS_COMPLETED) {
            return redirect()
            ->back()
            ->with('error', 'Completed orders cannot be cancelled.');
        }

        if ($order->markAsCancelled()) {
            $isBuyer = $order->user_id === Auth::id();
            $route = $isBuyer ? 'orders.show' : 'vendor.sales.show';

            return redirect()
            ->route($route, $order->unique_url)
            ->with('success', 'Order has been cancelled successfully.');
        }

        return redirect()
        ->back()
        ->with('error', 'Unable to cancel the order at this time.');
    }

    /**
     * Submit a review for a product in a completed order.
     */
    public function submitReview(Request $request, string $uniqueUrl, int $orderItemId): RedirectResponse
    {
        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if ($order->status !== Orders::STATUS_COMPLETED) {
            return redirect()
            ->route('orders.show', $order->unique_url)
            ->with('error', 'You can only review products from completed orders.');
        }

        $orderItem = $order->items()->where('id', $orderItemId)->first();

        if (! $orderItem) {
            abort(404);
        }

        $existingReview = ProductReviews::where('user_id', Auth::id())
        ->where('order_item_id', $orderItem->id)
        ->first();

        if ($existingReview) {
            return redirect()
            ->route('orders.show', $order->unique_url)
            ->with('error', 'You have already reviewed this product.');
        }

        $validated = $request->validate([
            'review_text' => 'required|string|min:8|max:800',
            'sentiment' => 'required|in:positive,mixed,negative',
        ]);

        ProductReviews::create([
            'product_id' => $orderItem->product_id,
            'user_id' => Auth::id(),
                               'order_id' => $order->id,
                               'order_item_id' => $orderItem->id,
                               'review_text' => $validated['review_text'],
                               'sentiment' => $validated['sentiment'],
        ]);

        return redirect()
        ->route('orders.show', $order->unique_url)
        ->with('success', 'Your review has been submitted successfully.');
    }
}
