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

use App\Models\Dispute;
use App\Models\Product;
use App\Models\ProductReviews;
use App\Models\User;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class VendorsController extends Controller
{
    /**
     * Display a listing of marketplace vendors.
     */
    public function index(): View
    {
        try {
            $vendors = User::whereHas('roles', function ($query) {
                $query->where('name', 'vendor');
            })
            ->with('profile')
            ->get(['id', 'username']);

            return view('vendors.index', compact('vendors'));
        } catch (Exception $exception) {
            Log::error('Error fetching vendors list: '.$exception->getMessage());

            return view('vendors.index', [
                'vendors' => collect(),
            ])->with('error', 'Unable to load vendors at this time.');
        }
    }

    /**
     * Display the specified vendor.
     */
    public function show(string $username): View|RedirectResponse
    {
        try {
            $vendor = User::whereHas('roles', function ($query) {
                $query->where('name', 'vendor');
            })
            ->with(['vendorProfile', 'profile', 'pgpKey'])
            ->where('username', $username)
            ->firstOrFail(['id', 'username']);

            if ($vendor->vendorProfile && $vendor->vendorProfile->vacation_mode) {
                return view('vendors.show', [
                    'vendor' => $vendor,
                    'vacation_mode' => true,
                    'products' => collect(),
                            'positiveCount' => 0,
                            'mixedCount' => 0,
                            'negativeCount' => 0,
                            'totalReviews' => 0,
                            'positivePercentage' => null,
                            'allReviews' => collect(),
                            'disputesWon' => 0,
                            'disputesOpen' => 0,
                            'disputesLost' => 0,
                            'totalDisputes' => 0,
                ]);
            }

            $products = Product::where('user_id', $vendor->id)
            ->active()
            ->latest()
            ->paginate(8);

            $reviewStats = $this->calculateVendorReviewStatistics($vendor->id);
            $disputeStats = $this->calculateVendorDisputeStatistics($vendor->id);

            $productIds = Product::where('user_id', $vendor->id)->pluck('id')->toArray();

            $allReviews = collect();

            if (! empty($productIds)) {
                $allReviews = ProductReviews::whereIn('product_id', $productIds)
                ->with(['user:id,username', 'product:id,name,slug'])
                ->orderBy('created_at', 'desc')
                ->paginate(8, ['*'], 'reviews_page');
            }

            return view('vendors.show', [
                'vendor' => $vendor,
                'vacation_mode' => false,
                'products' => $products,
                'positiveCount' => $reviewStats['positive'],
                'mixedCount' => $reviewStats['mixed'],
                'negativeCount' => $reviewStats['negative'],
                'totalReviews' => $reviewStats['total'],
                'positivePercentage' => $reviewStats['positivePercentage'],
                'allReviews' => $allReviews,
                'disputesWon' => $disputeStats['won'],
                'disputesOpen' => $disputeStats['open'],
                'disputesLost' => $disputeStats['lost'],
                'totalDisputes' => $disputeStats['total'],
            ]);
        } catch (Exception $exception) {
            Log::error('Error fetching vendor details: '.$exception->getMessage(), [
                'username' => $username,
            ]);

            return redirect()
            ->route('vendors.index')
            ->with('error', 'Vendor not found or unavailable.');
        }
    }

    /**
     * Calculate review statistics for all products of a vendor.
     */
    private function calculateVendorReviewStatistics(int $vendorId): array
    {
        $productIds = Product::where('user_id', $vendorId)->pluck('id')->toArray();

        if (empty($productIds)) {
            return [
                'positive' => 0,
                'mixed' => 0,
                'negative' => 0,
                'total' => 0,
                'positivePercentage' => null,
            ];
        }

        $reviewCounts = ProductReviews::whereIn('product_id', $productIds)
        ->select('sentiment', DB::raw('count(*) as count'))
        ->groupBy('sentiment')
        ->pluck('count', 'sentiment')
        ->toArray();

        $positiveCount = $reviewCounts[ProductReviews::SENTIMENT_POSITIVE] ?? 0;
        $mixedCount = $reviewCounts[ProductReviews::SENTIMENT_MIXED] ?? 0;
        $negativeCount = $reviewCounts[ProductReviews::SENTIMENT_NEGATIVE] ?? 0;
        $totalReviews = $positiveCount + $mixedCount + $negativeCount;

        $positivePercentage = $totalReviews > 0
        ? ($positiveCount / $totalReviews) * 100
        : null;

        return [
            'positive' => $positiveCount,
            'mixed' => $mixedCount,
            'negative' => $negativeCount,
            'total' => $totalReviews,
            'positivePercentage' => $positivePercentage,
        ];
    }

    /**
     * Calculate dispute statistics for a vendor.
     */
    private function calculateVendorDisputeStatistics(int $vendorId): array
    {
        $disputes = Dispute::getVendorDisputes($vendorId);

        if ($disputes->isEmpty()) {
            return [
                'won' => 0,
                'open' => 0,
                'lost' => 0,
                'total' => 0,
            ];
        }

        $wonCount = $disputes->where('status', Dispute::STATUS_VENDOR_PREVAILS)->count();
        $openCount = $disputes->where('status', Dispute::STATUS_ACTIVE)->count();
        $lostCount = $disputes->where('status', Dispute::STATUS_BUYER_PREVAILS)->count();
        $totalDisputes = $disputes->count();

        return [
            'won' => $wonCount,
            'open' => $openCount,
            'lost' => $lostCount,
            'total' => $totalDisputes,
        ];
    }
}
