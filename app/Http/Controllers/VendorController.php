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
use App\Models\Category;
use App\Models\Orders;
use App\Models\Product;
use App\Models\VendorProfile;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use finfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\NotReadableException;
use Intervention\Image\ImageManager;
use MoneroIntegrations\MoneroPhp\walletRPC;

class VendorController extends Controller
{
    protected ?walletRPC $walletRPC = null;

    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

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
     * Display the vendor dashboard.
     */
    public function index()
    {
        return view('vendor.index');
    }

    /**
     * Display a listing of the vendor's sales.
     */
    public function sales()
    {
        $sales = Orders::getVendorOrders(Auth::id());

        return view('vendor.sales.index', [
            'sales' => $sales,
        ]);
    }

    /**
     * Display the specified sale details.
     */
    public function showSale(string $uniqueUrl)
    {
        Orders::processAllAutoStatusChanges();

        $sale = Orders::findByUrl($uniqueUrl);

        if (! $sale) {
            abort(404);
        }

        if ($sale->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access.');
        }

        if ($sale->shouldAutoCancelIfNotSent()) {
            $sale->autoCancelIfNotSent();
            $sale->refresh();

            if ($sale->status === Orders::STATUS_CANCELLED) {
                return redirect()
                ->route('vendor.sales.show', $sale->unique_url)
                ->with('info', 'This order has been automatically cancelled because it was not marked as sent within 96 hours (4 days) after payment.');
            }
        }

        if ($sale->shouldAutoCompleteIfNotConfirmed()) {
            $sale->autoCompleteIfNotConfirmed();
            $sale->refresh();

            if ($sale->status === Orders::STATUS_COMPLETED) {
                return redirect()
                ->route('vendor.sales.show', $sale->unique_url)
                ->with('info', 'This order has been automatically marked as completed because it was not confirmed within 192 hours (8 days) after being marked as sent.');
            }
        }

        $totalItems = 0;

        foreach ($sale->items as $item) {
            if ($item->bulk_option && isset($item->bulk_option['amount'])) {
                $totalItems += $item->quantity * $item->bulk_option['amount'];
            } else {
                $totalItems += $item->quantity;
            }
        }

        return view('vendor.sales.show', [
            'sale' => $sale,
            'totalItems' => $totalItems,
        ]);
    }

    /**
     * Update the delivery text for products in an order.
     */
    public function updateDeliveryText(Request $request, string $uniqueUrl): RedirectResponse
    {
        $sale = Orders::findByUrl($uniqueUrl);

        if (! $sale) {
            abort(404);
        }

        if ($sale->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access.');
        }

        if ($sale->status !== Orders::STATUS_PAYMENT_RECEIVED) {
            return redirect()
            ->route('vendor.sales.show', $sale->unique_url)
            ->with('error', 'Delivery information can only be updated for orders with "Payment Received" status.');
        }

        try {
            $request->validate([
                'delivery_text.*' => 'required|string|min:8|max:800',
            ], [
                'delivery_text.*.required' => 'Delivery information is required for each product.',
                'delivery_text.*.min' => 'Delivery information must be at least 8 characters.',
                'delivery_text.*.max' => 'Delivery information cannot exceed 800 characters.',
            ]);
        } catch (ValidationException $exception) {
            return redirect()
            ->back()
            ->withInput()
            ->with('error', $exception->validator->errors()->first());
        }

        foreach ($sale->items as $item) {
            $productId = $item->product_id;

            if (isset($request->delivery_text[$productId])) {
                $item->update([
                    'delivery_text' => $request->delivery_text[$productId],
                ]);
            }
        }

        return redirect()
        ->route('vendor.sales.show', $sale->unique_url)
        ->with('success', 'Delivery information has been updated successfully.');
    }

    /**
     * Show the form for editing vendor appearance.
     */
    public function showAppearance()
    {
        $user = Auth::user();
        $vendorProfile = $user->vendorProfile ?? new VendorProfile();

        return view('vendor.appearance', compact('vendorProfile'));
    }

    /**
     * Update the vendor's appearance settings.
     */
    public function updateAppearance(Request $request): RedirectResponse
    {
        try {
            $request->validate([
                'description' => 'required|string|min:8|max:800',
                'vendor_policy' => 'nullable|string|min:8|max:1600',
                'vacation_mode' => 'required|in:0,1',
                'private_shop_mode' => 'required|in:0,1',
            ], [
                'description.required' => 'A description is required.',
                'description.min' => 'Description must be at least 8 characters.',
                'description.max' => 'Description cannot exceed 800 characters.',
                'vendor_policy.min' => 'Vendor policy must be at least 8 characters.',
                'vendor_policy.max' => 'Vendor policy cannot exceed 1600 characters.',
                'vacation_mode.in' => 'Invalid vacation mode value.',
                'private_shop_mode.in' => 'Invalid private shop mode value.',
            ]);
        } catch (ValidationException $exception) {
            return redirect()
            ->back()
            ->withInput()
            ->with('error', $exception->validator->errors()->first());
        }

        $user = Auth::user();
        $vendorProfile = $user->vendorProfile ?? new VendorProfile();

        if (! $user->vendorProfile) {
            $vendorProfile->user_id = $user->id;
        }

        $vendorProfile->description = $request->description;
        $vendorProfile->vendor_policy = $request->vendor_policy;
        $vendorProfile->vacation_mode = (bool) $request->vacation_mode;
        $vendorProfile->private_shop_mode = (bool) $request->private_shop_mode;
        $vendorProfile->save();

        return redirect()
        ->route('vendor.appearance')
        ->with('success', 'Vendor settings updated successfully.');
    }

    /**
     * Display the vendor's products.
     */
    public function myProducts()
    {
        $products = Product::where('user_id', Auth::id())
        ->select('id', 'name', 'type', 'slug')
        ->get();

        foreach ($products as $product) {
            $product->is_advertised = Advertisement::isProductAdvertised($product->id);
        }

        return view('vendor.my-products', compact('products'));
    }

    /**
     * Delete a product.
     */
    public function destroy(Product $product): RedirectResponse
    {
        if ($product->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $product->delete();

        return redirect()
        ->route('vendor.my-products')
        ->with('success', 'Product deleted successfully.');
    }

    /**
     * Show the form for creating a new product.
     */
    public function create(string $type)
    {
        if (! in_array($type, [Product::TYPE_CARGO, Product::TYPE_DIGITAL, Product::TYPE_DEADDROP], true)) {
            abort(404);
        }

        $categories = Category::with('children')->get();
        $measurementUnits = Product::getMeasurementUnits();
        $countries = $this->getCountries();

        return view('vendor.products.create', compact('type', 'categories', 'measurementUnits', 'countries'));
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(Request $request, string $type): RedirectResponse
    {
        if (! in_array($type, [Product::TYPE_CARGO, Product::TYPE_DIGITAL, Product::TYPE_DEADDROP], true)) {
            abort(404);
        }

        try {
            $countries = $this->getCountries();

            $validated = $request->validate([
                'name' => 'required|string|min:4|max:240',
                'description' => 'required|string|min:4|max:2400',
                'price' => 'required|numeric|min:0|max:80000',
                'category_id' => 'required|exists:categories,id',
                'product_picture' => ['nullable', 'file', 'max:800'],
                'additional_photos.*' => ['nullable', 'file', 'max:800'],
                'stock_amount' => 'required|integer|min:0|max:80000',
                'measurement_unit' => [
                    'required',
                    Rule::in(array_keys(Product::getMeasurementUnits())),
                ],
                'ships_from' => [
                    'required',
                    'string',
                    Rule::in($countries),
                ],
                'ships_to' => [
                    'required',
                    'string',
                    Rule::in($countries),
                ],
            ]);

            $deliveryOptions = collect($request->delivery_options ?? [])
            ->map(function ($option) {
                return [
                    'description' => trim($option['description'] ?? ''),
                  'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                ];
            })
            ->filter(function ($option) {
                return $option['description'] !== '' && is_numeric($option['price']);
            })
            ->values()
            ->all();

            $deliveryOptionName = $type === Product::TYPE_DEADDROP ? 'pickup window' : 'delivery';

            if (empty($deliveryOptions)) {
                return back()->withInput()->with('error', "At least one {$deliveryOptionName} option is required.");
            }

            if (count($deliveryOptions) > 4) {
                return back()->withInput()->with('error', "No more than 4 {$deliveryOptionName} options are allowed.");
            }

            foreach ($deliveryOptions as $option) {
                if (strlen($option['description']) < 4 || strlen($option['description']) > 160) {
                    return back()->withInput()->with('error', "{$deliveryOptionName} description must be between 4 and 160 characters.");
                }

                if ($option['price'] < 0 || $option['price'] > 80000) {
                    return back()->withInput()->with('error', "{$deliveryOptionName} price must be between 0 and 80000.");
                }
            }

            $bulkOptions = collect($request->bulk_options ?? [])
            ->map(function ($option) {
                return [
                    'amount' => is_numeric($option['amount'] ?? null) ? (float) $option['amount'] : null,
                  'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                ];
            })
            ->filter(function ($option) {
                return is_numeric($option['amount']) && is_numeric($option['price']);
            })
            ->values()
            ->all();

            if (! empty($bulkOptions)) {
                if (count($bulkOptions) > 8) {
                    return back()->withInput()->with('error', 'No more than 8 bulk options are allowed.');
                }

                foreach ($bulkOptions as $option) {
                    if ($option['amount'] < 0 || $option['amount'] > 80000) {
                        return back()->withInput()->with('error', 'Bulk option amount must be between 0 and 80000.');
                    }

                    if ($option['price'] < 0 || $option['price'] > 80000) {
                        return back()->withInput()->with('error', 'Bulk option price must be between 0 and 80000.');
                    }
                }
            }

            $productPicture = 'default-product-picture.png';

            if ($request->hasFile('product_picture')) {
                $productPicture = $this->handleProductPictureUpload($request->file('product_picture'));
            }

            $additionalPhotos = [];

            if ($request->hasFile('additional_photos')) {
                foreach ($request->file('additional_photos') as $index => $photo) {
                    if ($index >= 3) {
                        break;
                    }

                    try {
                        $additionalPhotos[] = $this->handleProductPictureUpload($photo);
                    } catch (Exception $exception) {
                        Log::warning('Failed to upload additional photo: '.$exception->getMessage(), [
                            'user_id' => Auth::id(),
                                     'photo_index' => $index,
                        ]);
                    }
                }
            }

            $productData = [
                'user_id' => Auth::id(),
                'name' => $validated['name'],
                'description' => $validated['description'],
                'price' => $validated['price'],
                'category_id' => $validated['category_id'],
                'active' => true,
                'product_picture' => $productPicture,
                'stock_amount' => $validated['stock_amount'],
                'measurement_unit' => $validated['measurement_unit'],
                'delivery_options' => $deliveryOptions,
                'bulk_options' => $bulkOptions,
                'ships_from' => $validated['ships_from'],
                'ships_to' => $validated['ships_to'],
                'additional_photos' => $additionalPhotos,
            ];

            match ($type) {
                Product::TYPE_CARGO => Product::createCargo($productData),
                Product::TYPE_DIGITAL => Product::createDigital($productData),
                Product::TYPE_DEADDROP => Product::createDeadDrop($productData),
            };

            $productTypeName = match ($type) {
                Product::TYPE_CARGO => 'Cargo',
                Product::TYPE_DIGITAL => 'Digital',
                Product::TYPE_DEADDROP => 'Dead Drop',
            };

            return redirect()
            ->route('vendor.index')
            ->with('success', "{$productTypeName} product created successfully.");
        } catch (Exception $exception) {
            Log::error("Failed to create {$type} product: ".$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'Failed to create product. Please try again.');
        }
    }

    /**
     * Show the form for editing a product.
     */
    public function edit(Product $product)
    {
        if ($product->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $categories = Category::with('children')->get();
        $measurementUnits = Product::getMeasurementUnits();
        $countries = $this->getCountries();

        return view('vendor.products.edit', compact('product', 'categories', 'measurementUnits', 'countries'));
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, Product $product): RedirectResponse
    {
        if ($product->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $countries = $this->getCountries();

            $validated = $request->validate([
                'description' => 'required|string|min:4|max:2400',
                'price' => 'required|numeric|min:0|max:80000',
                'category_id' => 'required|exists:categories,id',
                'stock_amount' => 'required|integer|min:0|max:80000',
                'measurement_unit' => [
                    'required',
                    Rule::in(array_keys(Product::getMeasurementUnits())),
                ],
                'ships_from' => [
                    'required',
                    'string',
                    Rule::in($countries),
                ],
                'ships_to' => [
                    'required',
                    'string',
                    Rule::in($countries),
                ],
            ]);

            $deliveryOptions = collect($request->delivery_options ?? [])
            ->map(function ($option) {
                return [
                    'description' => trim($option['description'] ?? ''),
                  'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                ];
            })
            ->filter(function ($option) {
                return $option['description'] !== '' && is_numeric($option['price']);
            })
            ->values()
            ->all();

            $deliveryOptionName = $product->type === Product::TYPE_DEADDROP ? 'pickup window' : 'delivery';

            if (empty($deliveryOptions)) {
                return back()->withInput()->with('error', "At least one {$deliveryOptionName} option is required.");
            }

            if (count($deliveryOptions) > 4) {
                return back()->withInput()->with('error', "No more than 4 {$deliveryOptionName} options are allowed.");
            }

            foreach ($deliveryOptions as $option) {
                if (strlen($option['description']) < 4 || strlen($option['description']) > 160) {
                    return back()->withInput()->with('error', "{$deliveryOptionName} description must be between 4 and 160 characters.");
                }

                if ($option['price'] < 0 || $option['price'] > 80000) {
                    return back()->withInput()->with('error', "{$deliveryOptionName} price must be between 0 and 80000.");
                }
            }

            $bulkOptions = collect($request->bulk_options ?? [])
            ->map(function ($option) {
                return [
                    'amount' => is_numeric($option['amount'] ?? null) ? (float) $option['amount'] : null,
                  'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                ];
            })
            ->filter(function ($option) {
                return is_numeric($option['amount']) && is_numeric($option['price']);
            })
            ->values()
            ->all();

            if (! empty($bulkOptions)) {
                if (count($bulkOptions) > 8) {
                    return back()->withInput()->with('error', 'No more than 8 bulk options are allowed.');
                }

                foreach ($bulkOptions as $option) {
                    if ($option['amount'] < 0 || $option['amount'] > 80000) {
                        return back()->withInput()->with('error', 'Bulk option amount must be between 0 and 80000.');
                    }

                    if ($option['price'] < 0 || $option['price'] > 80000) {
                        return back()->withInput()->with('error', 'Bulk option price must be between 0 and 80000.');
                    }
                }
            }

            $product->update([
                'description' => $validated['description'],
                'price' => $validated['price'],
                'category_id' => $validated['category_id'],
                'stock_amount' => $validated['stock_amount'],
                'measurement_unit' => $validated['measurement_unit'],
                'delivery_options' => $deliveryOptions,
                'bulk_options' => $bulkOptions,
                'ships_from' => $validated['ships_from'],
                'ships_to' => $validated['ships_to'],
                'active' => $request->has('active'),
            ]);

            $productTypeName = match ($product->type) {
                Product::TYPE_CARGO => 'Cargo',
                Product::TYPE_DIGITAL => 'Digital',
                Product::TYPE_DEADDROP => 'Dead Drop',
            };

            return redirect()
            ->route('vendor.my-products')
            ->with('success', "{$productTypeName} product updated successfully.");
        } catch (Exception $exception) {
            Log::error('Failed to update product: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'product_id' => $product->id,
            ]);

            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'Failed to update product. Please try again.');
        }
    }

    /**
     * Handle the product picture upload.
     */
    private function handleProductPictureUpload($file): string
    {
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());

            if (! in_array($mimeType, $this->allowedMimeTypes, true)) {
                throw new Exception('Invalid file type. Allowed types are JPEG, PNG, GIF, and WebP.');
            }

            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = time().'_'.Str::uuid().'.'.$extension;

            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($file)->scaleDown(width: 800, height: 800);

            $encodedImage = $this->encodeImage($image, $mimeType);

            if (! Storage::disk('private')->put('product_pictures/'.$filename, (string) $encodedImage)) {
                throw new Exception('Failed to save product picture to storage');
            }

            return $filename;
        } catch (NotReadableException $exception) {
            Log::error('Image processing failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            throw new Exception('Failed to process uploaded image. Please try a different image.');
        } catch (Exception $exception) {
            Log::error('Product picture upload failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            throw new Exception($exception->getMessage());
        }
    }

    /**
     * Get file extension from MIME type.
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        return $extensions[$mimeType] ?? 'jpg';
    }

    /**
     * Encode image based on MIME type.
     */
    private function encodeImage($image, string $mimeType)
    {
        return match ($mimeType) {
            'image/png' => $image->encode(new PngEncoder()),
            'image/webp' => $image->encode(new WebpEncoder()),
            'image/gif' => $image->encode(new GifEncoder()),
            default => $image->encode(new JpegEncoder(80)),
        };
    }

    /**
     * Prepare advertisement slot data with pricing and availability.
     */
    private function prepareAdvertisementSlots(): array
    {
        $basePrice = config('monero.advertisement_base_price');
        $slots = [];

        foreach (config('monero.advertisement_slot_multipliers') as $slot => $multiplier) {
            $price = $basePrice * $multiplier;
            $isAvailable = ! Advertisement::where('slot_number', $slot)
            ->where('payment_completed', true)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();

            $slots[] = [
                'number' => $slot,
                'price' => $price,
                'is_available' => $isAvailable,
            ];
        }

        return $slots;
    }

    /**
     * Show the rate limit page for advertisement requests.
     */
    public function showRateLimit()
    {
        $cooldownEnds = Advertisement::getCooldownEndTime(Auth::id());

        return view('vendor.advertisement.rate-limit', compact('cooldownEnds'));
    }

    /**
     * Show the form for creating a new advertisement.
     */
    public function createAdvertisement(Product $product): RedirectResponse|View
    {
        if ($product->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if (Advertisement::hasReachedDailyLimit(Auth::id())) {
            return redirect()->route('vendor.advertisement.rate-limit');
        }

        if (Advertisement::isProductAdvertised($product->id)) {
            return redirect()
            ->route('vendor.my-products')
            ->with('error', 'This product is already being advertised in another slot.');
        }

        $slots = $this->prepareAdvertisementSlots();

        return view('vendor.advertisement.create', compact('product', 'slots'));
    }

    /**
     * Store a new advertisement and initiate payment.
     */
    public function storeAdvertisement(Request $request, Product $product): RedirectResponse
    {
        if ($product->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        if (Advertisement::hasReachedDailyLimit(Auth::id())) {
            return redirect()->route('vendor.advertisement.rate-limit');
        }

        if (Advertisement::isProductAdvertised($product->id)) {
            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'This product is already being advertised in another slot.');
        }

        try {
            $validated = $request->validate([
                'slot_number' => [
                    'required',
                    'integer',
                    'min:1',
                    'max:8',
                    function ($attribute, $value, $fail) use ($request) {
                        $duration = (int) $request->duration_days;

                        if ($duration < 1) {
                            return;
                        }

                        if (! Advertisement::isSlotAvailable($value, now(), now()->addDays($duration))) {
                            $fail('This slot is currently occupied.');
                        }
                    },
                ],
                'duration_days' => [
                    'required',
                    'integer',
                    'min:'.config('monero.advertisement_min_duration', 1),
                                            'max:'.config('monero.advertisement_max_duration', 30),
                ],
            ]);
        } catch (ValidationException $exception) {
            return redirect()
            ->back()
            ->withInput()
            ->with('error', $exception->validator->errors()->first());
        }

        if (! $this->walletRPC) {
            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'Payment service is currently unavailable. Please try again later.');
        }

        try {
            $requiredAmount = Advertisement::calculateRequiredAmount(
                $validated['slot_number'],
                $validated['duration_days']
            );

            $result = $this->walletRPC->create_address(
                0,
                'Advertisement Payment '.Auth::id().'_'.time()
            );

            $advertisement = new Advertisement([
                'product_id' => $product->id,
                'user_id' => Auth::id(),
                                               'slot_number' => $validated['slot_number'],
                                               'duration_days' => $validated['duration_days'],
                                               'payment_address' => $result['address'],
                                               'payment_address_index' => $result['address_index'],
                                               'required_amount' => $requiredAmount,
                                               'expires_at' => now()->addMinutes((int) config('monero.address_expiration_time')),
            ]);

            $advertisement->save();

            return redirect()->route('vendor.advertisement.payment', $advertisement->payment_identifier);
        } catch (Exception $exception) {
            Log::error('Failed to create advertisement: '.$exception->getMessage());

            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'Failed to create advertisement. Please try again.');
        }
    }

    /**
     * Show the advertisement payment page.
     */
    public function showAdvertisementPayment(string $identifier)
    {
        $advertisement = Advertisement::where('payment_identifier', $identifier)
        ->where('user_id', Auth::id())
        ->firstOrFail();

        if ($advertisement->isExpired()) {
            return redirect()
            ->route('vendor.my-products')
            ->with('error', 'Payment window has expired.');
        }

        if (! $this->walletRPC) {
            return view('vendor.advertisement.payment', [
                'advertisement' => $advertisement,
                'qrCode' => null,
                'error' => 'Payment service is currently unavailable. Please try refreshing the page later.',
            ]);
        }

        try {
            $transfers = $this->walletRPC->get_transfers([
                'in' => true,
                'pool' => true,
                'subaddr_indices' => [$advertisement->payment_address_index],
            ]);

            $minPaymentPercentage = config('monero.advertisement_minimum_payment_percentage');
            $minAcceptedAmount = $advertisement->required_amount * $minPaymentPercentage;

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

            $advertisement->total_received = $totalReceived;

            if ($totalReceived >= $advertisement->required_amount && ! $advertisement->payment_completed) {
                $advertisement->payment_completed = true;
                $advertisement->payment_completed_at = now();
                $advertisement->starts_at = now();
                $advertisement->ends_at = now()->addDays((int) $advertisement->duration_days);
            }

            $advertisement->save();

            $qrCode = null;

            if (! $advertisement->payment_completed) {
                $qrCode = $this->generateQrCode($advertisement->payment_address);
            }

            return view('vendor.advertisement.payment', [
                'advertisement' => $advertisement,
                'qrCode' => $qrCode,
            ]);
        } catch (Exception $exception) {
            Log::error('Error processing advertisement payment: '.$exception->getMessage());

            return view('vendor.advertisement.payment', [
                'advertisement' => $advertisement,
                'qrCode' => null,
                'error' => 'Error checking payment status. Please try refreshing the page.',
            ]);
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
     * Load countries from storage.
     */
    private function getCountries(): array
    {
        $countries = json_decode(file_get_contents(storage_path('app/country.json')), true);

        return is_array($countries) ? $countries : [];
    }
}
