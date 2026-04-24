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
use App\Models\Product;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CartController extends Controller
{
    /**
     * Display the user's cart.
     */
    public function index(XmrPriceController $xmrPriceController): View
    {
        $cartItems = Cart::where('user_id', Auth::id())
        ->with(['product', 'product.user'])
        ->get();

        $deletedProductItems = $cartItems->filter(function ($item) {
            return $item->hasDeletedProduct();
        });

        if ($deletedProductItems->isNotEmpty()) {
            foreach ($deletedProductItems as $item) {
                $item->delete();
            }

            $cartItems = Cart::where('user_id', Auth::id())
            ->with(['product', 'product.user'])
            ->get();

            session()->flash('info', 'Some items were automatically removed from your cart because their products have been deleted.');
        }

        $xmrPrice = $xmrPriceController->getXmrPrice();
        $cartTotal = Cart::getCartTotal(Auth::user());
        $xmrTotal = is_numeric($xmrPrice) && $xmrPrice > 0
        ? $cartTotal / $xmrPrice
        : null;

        $measurementUnits = Product::getMeasurementUnits();

        return view('cart.index', [
            'cartItems' => $cartItems,
            'cartTotal' => $cartTotal,
            'xmrTotal' => $xmrTotal,
            'xmrPrice' => $xmrPrice,
            'measurementUnits' => $measurementUnits,
        ]);
    }

    /**
     * Add a product to cart.
     */
    public function store(Request $request, Product $product): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1|max:80000',
                'delivery_option' => 'required|integer|min:0',
                'bulk_option' => 'nullable|integer|min:0',
            ]);

            $validation = Cart::validateProductAddition(Auth::user(), $product);

            if (! $validation['valid']) {
                $errorMessage = match ($validation['reason']) {
                    'different_vendor' => 'You can only add products from the same vendor to your cart.',
                    'inactive' => 'This product is currently not available.',
                    'vacation' => 'This vendor is currently on vacation.',
                    'out_of_stock' => 'This product is out of stock.',
                    default => 'Unable to add product to cart.',
                };

                return back()->with('error', $errorMessage);
            }

            $deliveryOptions = $product->delivery_options;

            if (! isset($deliveryOptions[$validated['delivery_option']])) {
                return back()->with('error', 'Invalid delivery option selected.');
            }

            $selectedDelivery = $deliveryOptions[$validated['delivery_option']];
            $price = $product->price;
            $selectedBulk = null;
            $quantity = $validated['quantity'];

            if (
                array_key_exists('bulk_option', $validated) &&
                $validated['bulk_option'] !== null &&
                $validated['bulk_option'] !== '' &&
                $product->bulk_options
            ) {
                if (! isset($product->bulk_options[$validated['bulk_option']])) {
                    return back()->with('error', 'Invalid bulk option selected.');
                }

                $selectedBulk = $product->bulk_options[$validated['bulk_option']];

                if ($quantity % $selectedBulk['amount'] !== 0) {
                    return back()->with('error', 'Quantity must be a multiple of '.$selectedBulk['amount'].' when using bulk option.');
                }

                $price = $selectedBulk['price'];
                $quantity = (int) ($quantity / $selectedBulk['amount']);
            }

            $stockValidation = Cart::validateStockAvailability(
                $product,
                $quantity,
                $selectedBulk
            );

            if (! $stockValidation['valid']) {
                $measurementUnits = Product::getMeasurementUnits();
                $formattedUnit = $measurementUnits[$product->measurement_unit] ?? $product->measurement_unit;

                return back()->with('error', sprintf(
                    'Insufficient stock. Available: %d %s, Requested: %d %s',
                    $stockValidation['available'],
                    $formattedUnit,
                    $stockValidation['requested'],
                    $formattedUnit
                ));
            }

            Cart::updateOrCreate(
                [
                    'user_id' => Auth::id(),
                                 'product_id' => $product->id,
                ],
                [
                    'quantity' => $quantity,
                    'price' => $price,
                    'selected_delivery_option' => $selectedDelivery,
                    'selected_bulk_option' => $selectedBulk,
                ]
            );

            return redirect()
            ->route('cart.index')
            ->with('success', 'Product added to cart successfully.');
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to add product to cart.');
        }
    }

    /**
     * Update cart item quantity.
     */
    public function update(Request $request, Cart $cart): RedirectResponse
    {
        try {
            if ($cart->user_id !== Auth::id()) {
                abort(403);
            }

            $validated = $request->validate([
                'quantity' => 'required|integer|min:1|max:80000',
            ]);

            $stockValidation = Cart::validateStockAvailability(
                $cart->product,
                $validated['quantity'],
                $cart->selected_bulk_option
            );

            if (! $stockValidation['valid']) {
                $measurementUnits = Product::getMeasurementUnits();
                $formattedUnit = $measurementUnits[$cart->product->measurement_unit] ?? $cart->product->measurement_unit;

                return back()->with('error', sprintf(
                    'Insufficient stock. Available: %d %s, Requested: %d %s',
                    $stockValidation['available'],
                    $formattedUnit,
                    $stockValidation['requested'],
                    $formattedUnit
                ));
            }

            $cart->update([
                'quantity' => $validated['quantity'],
            ]);

            return redirect()
            ->route('cart.index')
            ->with('success', 'Cart updated successfully.');
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to update cart.');
        }
    }

    /**
     * Remove a specific item from cart.
     */
    public function destroy(Cart $cart): RedirectResponse
    {
        try {
            if ($cart->user_id !== Auth::id()) {
                abort(403);
            }

            $cart->delete();

            return redirect()
            ->route('cart.index')
            ->with('success', 'Item removed from cart successfully.');
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to remove item from cart.');
        }
    }

    /**
     * Clear all items from cart.
     */
    public function clear(): RedirectResponse
    {
        try {
            Cart::where('user_id', Auth::id())->delete();

            return redirect()
            ->route('cart.index')
            ->with('success', 'Cart cleared successfully.');
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to clear cart.');
        }
    }

    /**
     * Save encrypted message for cart item.
     */
    public function saveMessage(Request $request, Cart $cart): RedirectResponse
    {
        try {
            if ($cart->user_id !== Auth::id()) {
                abort(403);
            }

            $validated = $request->validate([
                'message' => 'required|string|min:4|max:1600',
            ]);

            if (! $cart->product->user->pgpKey) {
                return back()->with('error', 'Vendor does not have a PGP key set up.');
            }

            $encryptedMessage = $cart->encryptMessageForVendor($validated['message']);

            if ($encryptedMessage === false) {
                return back()->with('error', 'Failed to encrypt message. Please try again.');
            }

            $cart->update([
                'encrypted_message' => $encryptedMessage,
            ]);

            return back()->with('success', 'Message encrypted and saved successfully.');
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to save message.');
        }
    }

    /**
     * Show checkout page.
     */
    public function checkout(XmrPriceController $xmrPriceController): View
    {
        $cartItems = Cart::where('user_id', Auth::id())
        ->with(['product', 'product.user'])
        ->get();

        $deletedProductItems = $cartItems->filter(function ($item) {
            return $item->hasDeletedProduct();
        });

        if ($deletedProductItems->isNotEmpty()) {
            foreach ($deletedProductItems as $item) {
                $item->delete();
            }

            $cartItems = Cart::where('user_id', Auth::id())
            ->with(['product', 'product.user'])
            ->get();

            session()->flash('info', 'Some items were automatically removed from your cart because their products have been deleted.');
        }

        $subtotal = Cart::getCartTotal(Auth::user());
        $commissionPercentage = config('marketplace.commission_percentage');
        $commission = ($subtotal * $commissionPercentage) / 100;
        $total = $subtotal + $commission;

        $xmrPrice = $xmrPriceController->getXmrPrice();
        $xmrTotal = is_numeric($xmrPrice) && $xmrPrice > 0
        ? $total / $xmrPrice
        : null;

        $measurementUnits = Product::getMeasurementUnits();

        $hasEncryptedMessage = $cartItems->contains(function ($item) {
            return $item->encrypted_message;
        });

        $messageItem = $cartItems->first(function ($item) {
            return $item->encrypted_message;
        });

        return view('cart.checkout', [
            'cartItems' => $cartItems,
            'subtotal' => $subtotal,
            'commissionPercentage' => $commissionPercentage,
            'commission' => $commission,
            'total' => $total,
            'xmrPrice' => $xmrPrice,
            'xmrTotal' => $xmrTotal,
            'measurementUnits' => $measurementUnits,
            'hasEncryptedMessage' => $hasEncryptedMessage,
            'messageItem' => $messageItem,
        ]);
    }
}
