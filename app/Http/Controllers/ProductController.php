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

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReviews;
use Exception;
use finfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Display a listing of products.
     */
    public function index(Request $request): View|RedirectResponse
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'min:1', 'max:80'],
                'vendor' => ['nullable', 'string', 'min:1', 'max:16'],
                'type' => ['nullable', Rule::in([
                    Product::TYPE_DIGITAL,
                    Product::TYPE_CARGO,
                    Product::TYPE_DEADDROP,
                ])],
                'category' => ['nullable', 'integer', 'exists:categories,id'],
                'sort_price' => ['nullable', Rule::in(['asc', 'desc'])],
            ]);

            $filters = collect($request->only([
                'search',
                'vendor',
                'type',
                'category',
                'sort_price',
            ]))
            ->filter(function ($value) {
                return $value !== null && $value !== '';
            })
            ->toArray();

            $query = Product::with([
                'user' => function ($query) {
                    $query->select('id', 'username');
                },
            ])
            ->select('products.*')
            ->active()
            ->whereHas('user', function ($query) {
                $query->whereDoesntHave('vendorProfile', function ($q) {
                    $q->where(function ($subQuery) {
                        $subQuery->where('vacation_mode', true)
                        ->orWhere(function ($privShop) {
                            $privShop->where('private_shop_mode', true)
                            ->whereNotExists(function ($ref) {
                                $ref->select(DB::raw(1))
                                ->from('private_shops')
                                ->whereColumn('private_shops.vendor_id', 'users.id')
                                ->where('private_shops.user_id', auth()->id() ?: 0);
                            });
                        });
                    });
                })->orWhereHas('vendorProfile', function ($q) {
                    $q->where('vacation_mode', false)
                    ->where(function ($subQuery) {
                        $subQuery->where('private_shop_mode', false)
                        ->orWhereExists(function ($ref) {
                            $ref->select(DB::raw(1))
                            ->from('private_shops')
                            ->whereColumn('private_shops.vendor_id', 'users.id')
                            ->where('private_shops.user_id', auth()->id() ?: 0);
                        });
                    });
                });
            });

            if (isset($filters['search'])) {
                $searchTerm = strip_tags($filters['search']);
                $query->where('name', 'like', '%'.addcslashes($searchTerm, '%_').'%');
            }

            if (isset($filters['vendor'])) {
                $vendorTerm = strip_tags($filters['vendor']);

                $query->whereHas('user', function ($q) use ($vendorTerm) {
                    $q->where('username', 'like', '%'.addcslashes($vendorTerm, '%_').'%');
                });
            }

            if (isset($filters['type'])) {
                $query->ofType($filters['type']);
            }

            if (isset($filters['category'])) {
                $query->where('category_id', (int) $filters['category']);
            }

            if (isset($filters['sort_price'])) {
                $query->orderBy('price', $filters['sort_price']);
            } else {
                $query->inRandomOrder();
            }

            $products = $query->paginate(12)->withQueryString();
            $categories = Category::select('id', 'name')->get();

            $requestParams = $request->except('page');

            if (count($requestParams) > count($filters)) {
                return redirect()->route('products.index', $filters);
            }

            return view('products.index', [
                'products' => $products,
                'categories' => $categories,
                'currentType' => $filters['type'] ?? null,
                'filters' => $filters,
            ]);
        } catch (ValidationException $exception) {
            $errorMessage = implode(' ', $exception->validator->errors()->all());

            return redirect()
            ->route('products.index')
            ->with('error', $errorMessage)
            ->withInput();
        } catch (Exception $exception) {
            Log::error('Product listing failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->route('products.index')
            ->with('error', 'An error occurred while processing your request.');
        }
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product, XmrPriceController $xmrPriceController): View|RedirectResponse
    {
        try {
            $xmrPrice = $xmrPriceController->getXmrPrice();
            $xmrPrice = (is_numeric($xmrPrice) && $xmrPrice > 0)
            ? $product->price / $xmrPrice
            : $xmrPrice;

            if (! $product->active) {
                abort(404);
            }

            $product->load([
                'user:id,username',
                'user.vendorProfile:id,user_id,vacation_mode,private_shop_mode,vendor_policy',
                'category:id,name',
            ]);

            if ($product->user->vendorProfile && $product->user->vendorProfile->vacation_mode) {
                return view('products.show', [
                    'product' => $product,
                    'title' => $product->name,
                    'vendor_on_vacation' => true,
                    'vendor_shop_private' => false,
                ]);
            }

            if ($product->user->vendorProfile && $product->user->vendorProfile->private_shop_mode) {
                $hasReferenceCode = false;

                if (Auth::check()) {
                    $hasReferenceCode = DB::table('private_shops')
                    ->where('user_id', Auth::id())
                    ->where('vendor_id', $product->user->id)
                    ->exists();
                }

                if (! $hasReferenceCode) {
                    return view('products.show', [
                        'product' => $product,
                        'title' => $product->name,
                        'vendor_on_vacation' => false,
                        'vendor_shop_private' => true,
                    ]);
                }
            }

            $measurementUnits = Product::getMeasurementUnits();
            $formattedMeasurementUnit = $measurementUnits[$product->measurement_unit] ?? $product->measurement_unit;

            $formattedBulkOptions = $product->getFormattedBulkOptions($xmrPrice);
            $formattedDeliveryOptions = $product->getFormattedDeliveryOptions($xmrPrice);

            $reviews = ProductReviews::getProductReviews($product->id);

            $positivePercentage = $product->getPositiveReviewPercentage();
            $positiveCount = $product->getPositiveReviewsCount();
            $mixedCount = $product->getMixedReviewsCount();
            $negativeCount = $product->getNegativeReviewsCount();
            $totalReviews = $positiveCount + $mixedCount + $negativeCount;

            return view('products.show', [
                'product' => $product,
                'title' => $product->name,
                'vendor_on_vacation' => false,
                'vendor_shop_private' => false,
                'xmrPrice' => $xmrPrice,
                'formattedMeasurementUnit' => $formattedMeasurementUnit,
                'formattedBulkOptions' => $formattedBulkOptions,
                'formattedDeliveryOptions' => $formattedDeliveryOptions,
                'reviews' => $reviews,
                'positivePercentage' => $positivePercentage,
                'positiveCount' => $positiveCount,
                'mixedCount' => $mixedCount,
                'negativeCount' => $negativeCount,
                'totalReviews' => $totalReviews,
            ]);
        } catch (Exception $exception) {
            Log::error('Product load failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'product_id' => $product->id ?? null,
            ]);

            return redirect()
            ->route('products.index')
            ->with('error', 'An error occurred while loading the product.');
        }
    }

    /**
     * Display the product picture.
     */
    public function showPicture(string $filename): Response
    {
        try {
            if (! Auth::check()) {
                abort(403, 'Unauthorized action.');
            }

            if ($filename === 'default-product-picture.png') {
                return response()->file(public_path('images/default-product-picture.png'));
            }

            $path = 'product_pictures/'.$filename;

            if (! Storage::disk('private')->exists($path)) {
                throw new Exception('Product picture not found');
            }

            $file = Storage::disk('private')->get($path);

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($file);

            if (! in_array($mimeType, $this->allowedMimeTypes, true)) {
                throw new Exception('Invalid file type');
            }

            return ResponseFacade::make($file, 200, [
                'Content-Type' => $mimeType,
            ]);
        } catch (Exception $exception) {
            Log::error('Failed to retrieve product picture: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'filename' => $filename,
            ]);

            return response()->file(public_path('images/default-product-picture.png'));
        }
    }
}
