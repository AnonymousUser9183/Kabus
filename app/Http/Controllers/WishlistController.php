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

use App\Models\Product;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WishlistController extends Controller
{
    /**
     * Display the authenticated user's wishlist.
     */
    public function index(): View|RedirectResponse
    {
        try {
            $wishlistedProducts = Auth::user()
            ->wishlist()
            ->with(['user:id,username', 'category:id,name'])
            ->paginate(12);

            return view('wishlist', [
                'products' => $wishlistedProducts,
                'title' => 'My Wishlist',
            ]);
        } catch (Exception $exception) {
            Log::error('Failed to load wishlist.', [
                'user_id' => Auth::id(),
                       'message' => $exception->getMessage(),
            ]);

            return redirect()
            ->route('products.index')
            ->with('error', 'An error occurred while loading your wishlist.');
        }
    }

    /**
     * Add a product to the authenticated user's wishlist.
     */
    public function store(Product $product): RedirectResponse
    {
        try {
            if (! $product->active) {
                return back()->with('error', 'This product is no longer available.');
            }

            if (Auth::user()->hasWishlisted($product->id)) {
                return back()->with('error', 'This product is already in your wishlist.');
            }

            Auth::user()->wishlist()->attach($product->id);

            return back()->with('success', 'Product added to your wishlist.');
        } catch (Exception $exception) {
            Log::error('Failed to add product to wishlist.', [
                'user_id' => Auth::id(),
                       'product_id' => $product->id,
                       'message' => $exception->getMessage(),
            ]);

            return back()->with('error', 'An error occurred while adding the product to your wishlist.');
        }
    }

    /**
     * Remove a product from the authenticated user's wishlist.
     */
    public function destroy(Product $product): RedirectResponse
    {
        try {
            Auth::user()->wishlist()->detach($product->id);

            return back()->with('success', 'Product removed from your wishlist.');
        } catch (Exception $exception) {
            Log::error('Failed to remove product from wishlist.', [
                'user_id' => Auth::id(),
                       'product_id' => $product->id,
                       'message' => $exception->getMessage(),
            ]);

            return back()->with('error', 'An error occurred while removing the product from your wishlist.');
        }
    }

    /**
     * Clear all products from the authenticated user's wishlist.
     */
    public function clearAll(): RedirectResponse
    {
        try {
            Auth::user()->wishlist()->detach();

            return back()->with('success', 'Your wishlist has been cleared.');
        } catch (Exception $exception) {
            Log::error('Failed to clear wishlist.', [
                'user_id' => Auth::id(),
                       'message' => $exception->getMessage(),
            ]);

            return back()->with('error', 'An error occurred while clearing your wishlist.');
        }
    }
}
