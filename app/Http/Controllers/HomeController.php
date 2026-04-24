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

use App\Models\Advertisement;
use App\Models\FeaturedProduct;
use App\Models\Popup;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Display the application home page.
     */
    public function index(XmrPriceController $xmrPriceController): View
    {
        $popup = Popup::query()->latest()->first();

        $advertisements = Advertisement::with([
            'product' => function ($query) {
                $query->withTrashed()->with('user');
            },
        ])->get();

        $xmrPrice = $xmrPriceController->getXmrPrice();

        $adSlots = [];

        foreach ($advertisements as $ad) {
            if (! $ad->product || $ad->product->trashed()) {
                continue;
            }

            $measurementUnits = Product::getMeasurementUnits();
            $formattedMeasurementUnit = $measurementUnits[$ad->product->measurement_unit] ?? $ad->product->measurement_unit;

            $productXmrPrice = (is_numeric($xmrPrice) && $xmrPrice > 0)
            ? $ad->product->price / $xmrPrice
            : null;

            $formattedBulkOptions = $ad->product->getFormattedBulkOptions($xmrPrice);
            $formattedDeliveryOptions = $ad->product->getFormattedDeliveryOptions($xmrPrice);

            $adSlots[$ad->slot_number] = [
                'product' => $ad->product,
                'vendor' => $ad->product->user,
                'ends_at' => $ad->ends_at,
                'measurement_unit' => $formattedMeasurementUnit,
                'xmr_price' => $productXmrPrice,
                'bulk_options' => $formattedBulkOptions,
                'delivery_options' => $formattedDeliveryOptions,
            ];
        }

        $featuredProducts = FeaturedProduct::getAllFeaturedProducts();
        $formattedFeaturedProducts = [];

        foreach ($featuredProducts as $featured) {
            if (! $featured->product || $featured->product->trashed()) {
                continue;
            }

            $measurementUnits = Product::getMeasurementUnits();
            $formattedMeasurementUnit = $measurementUnits[$featured->product->measurement_unit] ?? $featured->product->measurement_unit;

            $productXmrPrice = (is_numeric($xmrPrice) && $xmrPrice > 0)
            ? $featured->product->price / $xmrPrice
            : null;

            $formattedBulkOptions = $featured->product->getFormattedBulkOptions($xmrPrice);
            $formattedDeliveryOptions = $featured->product->getFormattedDeliveryOptions($xmrPrice);

            $formattedFeaturedProducts[] = [
                'product' => $featured->product,
                'vendor' => $featured->product->user,
                'measurement_unit' => $formattedMeasurementUnit,
                'xmr_price' => $productXmrPrice,
                'bulk_options' => $formattedBulkOptions,
                'delivery_options' => $formattedDeliveryOptions,
            ];
        }

        return view('home', [
            'username' => Auth::user()->username,
                    'popup' => $popup,
                    'adSlots' => $adSlots,
                    'featuredProducts' => $formattedFeaturedProducts,
        ]);
    }
}
