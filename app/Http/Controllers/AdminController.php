<?php

/*
|--------------------------------------------------------------------------
| Copyright Notice
|--------------------------------------------------------------------------
| Updated for Laravel 13.3.0 by AnonymousUser9183 / The Erebus Development Team.
| Original Kabus Marketplace Script created by Sukunetsiz.
|--------------------------------------------------------------------------
*/

namespace App\Http\Controllers;

use App\Models\BannedUser;
use App\Models\Category;
use App\Models\FeaturedProduct;
use App\Models\Notification;
use App\Models\PgpKey;
use App\Models\Popup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SupportRequest;
use App\Models\User;
use App\Models\VendorPayment;
use Exception;
use finfo;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\NotReadableException;
use Intervention\Image\ImageManager;
use MoneroIntegrations\MoneroPhp\walletRPC;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index');
    }

    public function showUpdateCanary(): View
    {
        $currentCanary = Storage::exists('public/canary.txt')
            ? Storage::get('public/canary.txt')
            : '';

        return view('admin.canary', compact('currentCanary'));
    }

    public function updateCanary(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'canary' => ['required', 'string', 'max:3200'],
            ]);

            Storage::put('public/canary.txt', $validated['canary']);

            return redirect()
                ->route('admin.canary')
                ->with('success', 'Canary updated successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error updating canary: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update canary. Please try again.');
        }
    }

    public function showLogs(): View
    {
        return view('admin.logs.index');
    }

    private function getFilteredLogs(array $logTypes, ?string $searchQuery = null): array
    {
        $logPath = storage_path('logs/laravel.log');
        $logs = [];

        if (! File::exists($logPath)) {
            return $logs;
        }

        $content = File::get($logPath);
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*?)(\n|\z)/s';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $logType = strtolower($match[3]);

            if (! in_array($logType, $logTypes, true)) {
                continue;
            }

            if ($searchQuery !== null && $searchQuery !== '' && stripos($match[4], $searchQuery) === false) {
                continue;
            }

            $logs[] = [
                'datetime' => $match[1],
                'type' => $match[3],
                'message' => $match[4],
                'id' => md5($match[1] . $match[3] . $match[4]),
            ];
        }

        return array_reverse($logs);
    }

    public function showLogsByType(string $type, Request $request): View
    {
        $logTypes = match ($type) {
            'error' => ['error', 'critical', 'alert', 'emergency'],
            'warning' => ['warning', 'notice'],
            'info' => ['info', 'debug'],
            default => abort(404),
        };

        $searchQuery = $request->string('search')->toString();
        $logs = $this->getFilteredLogs($logTypes, $searchQuery);

        return view('admin.logs.show', compact('logs', 'type', 'searchQuery'));
    }

    public function deleteLogs(string $type): RedirectResponse
    {
        $logPath = storage_path('logs/laravel.log');

        if (File::exists($logPath)) {
            $content = File::get($logPath);
            $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*?)(\n|\z)/s';

            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

            $newContent = '';

            foreach ($matches as $match) {
                $logType = strtolower($match[3]);

                if ($type === 'error' && ! in_array($logType, ['error', 'critical', 'alert', 'emergency'], true)) {
                    $newContent .= $match[0];
                } elseif ($type === 'warning' && ! in_array($logType, ['warning', 'notice'], true)) {
                    $newContent .= $match[0];
                } elseif ($type === 'info' && ! in_array($logType, ['info', 'debug'], true)) {
                    $newContent .= $match[0];
                }
            }

            File::put($logPath, $newContent);
        }

        return redirect()
            ->route('admin.logs')
            ->with('success', ucfirst($type) . ' logs deleted successfully.');
    }

    public function deleteSelectedLogs(Request $request, string $type): RedirectResponse
    {
        $selectedLogs = $request->input('selected_logs', []);

        if (empty($selectedLogs)) {
            return redirect()
                ->route('admin.logs.show', $type)
                ->with('error', 'No logs selected for deletion.');
        }

        $logPath = storage_path('logs/laravel.log');

        if (File::exists($logPath)) {
            $content = File::get($logPath);
            $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*?)(\n|\z)/s';

            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

            $newContent = '';

            foreach ($matches as $match) {
                $logId = md5($match[1] . $match[3] . $match[4]);

                if (in_array($logId, $selectedLogs, true)) {
                    continue;
                }

                $newContent .= $match[0];
            }

            File::put($logPath, $newContent);
        }

        return redirect()
            ->route('admin.logs.show', $type)
            ->with('success', count($selectedLogs) . ' logs deleted successfully.');
    }

    public function userList(): View
    {
        $users = User::query()
            ->orderBy('username')
            ->paginate(32);

        return view('admin.users.list', compact('users'));
    }

    public function userDetails(User $user): View
    {
        $user->load('referrer');

        return view('admin.users.details', compact('user'));
    }

    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['in:admin,vendor'],
        ]);

        $roles = $validated['roles'] ?? [];
        $roleIds = Role::query()
            ->whereIn('name', $roles)
            ->pluck('id');

        $user->roles()->sync($roleIds);

        return redirect()
            ->route('admin.users.details', $user)
            ->with('success', 'User roles updated successfully.');
    }

    public function banUser(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'duration' => ['required', 'integer', 'min:1'],
        ]);

        $bannedUntil = now()->addDays((int) $validated['duration']);

        BannedUser::updateOrCreate(
            ['user_id' => $user->id],
            [
                'reason' => $validated['reason'],
                'banned_until' => $bannedUntil,
            ]
        );

        Log::info("User banned: ID {$user->id} banned until {$bannedUntil} for reason: {$validated['reason']}");

        return redirect()
            ->route('admin.users.details', $user)
            ->with('success', 'User banned successfully.');
    }

    public function unbanUser(User $user): RedirectResponse
    {
        $user->bannedUser()->delete();

        Log::info("User unbanned: ID {$user->id}");

        return redirect()
            ->route('admin.users.details', $user)
            ->with('success', 'User unbanned successfully.');
    }

    public function supportRequests(): View
    {
        $requests = SupportRequest::mainRequests()
            ->with(['user', 'latestMessage'])
            ->orderByDesc('created_at')
            ->paginate(16);

        return view('admin.support.list', compact('requests'));
    }

    public function showSupportRequest(SupportRequest $supportRequest): RedirectResponse|View
    {
        if (! $supportRequest->isMainRequest()) {
            return redirect()
                ->route('admin.support.requests')
                ->with('error', 'Invalid support request.');
        }

        $messages = $supportRequest->messages()
            ->with('user')
            ->get();

        return view('admin.support.show', compact('supportRequest', 'messages'));
    }

    public function replySupportRequest(Request $request, SupportRequest $supportRequest): RedirectResponse
    {
        if (! $supportRequest->isMainRequest()) {
            return redirect()
                ->route('admin.support.requests')
                ->with('error', 'Invalid support request.');
        }

        if ($supportRequest->status === 'closed') {
            return redirect()
                ->route('admin.support.show', $supportRequest->ticket_id)
                ->with('error', 'Cannot reply to a closed ticket. Please update the ticket status to open or in progress first.');
        }

        try {
            $validated = $request->validate([
                'message' => ['required', 'string', 'max:5000'],
            ]);

            $supportRequest->messages()->create([
                'user_id' => auth()->id(),
                'message' => $validated['message'],
                'is_admin_reply' => true,
            ]);

            $notification = Notification::create([
                'title' => 'Support Request Update',
                'message' => 'An admin has replied to your support request: "' . $supportRequest->title . '"',
                'type' => 'support',
            ]);

            $notification->users()->attach($supportRequest->user_id);

            if ($supportRequest->status === 'open') {
                $supportRequest->update(['status' => 'in_progress']);
            }

            return redirect()
                ->route('admin.support.show', $supportRequest->ticket_id)
                ->with('success', 'Reply sent successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error replying to support request: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to send reply. Please try again.');
        }
    }

    public function updateSupportStatus(Request $request, SupportRequest $supportRequest): RedirectResponse
    {
        if (! $supportRequest->isMainRequest()) {
            return redirect()
                ->route('admin.support.requests')
                ->with('error', 'Invalid support request.');
        }

        try {
            $validated = $request->validate([
                'status' => ['required', 'in:open,in_progress,closed'],
            ]);

            $supportRequest->update([
                'status' => $validated['status'],
            ]);

            if ($validated['status'] === 'closed') {
                Log::info("Support ticket {$supportRequest->ticket_id} closed by admin {$request->user()->id}");
            }

            return redirect()
                ->route('admin.support.show', $supportRequest->ticket_id)
                ->with('success', 'Status updated successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error updating support status: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update support status. Please try again.');
        }
    }

    public function showBulkMessage(): View
    {
        $roles = Role::all();

        return view('admin.bulk-message.create', compact('roles'));
    }

    public function sendBulkMessage(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'min:3', 'max:255'],
                'message' => ['required', 'string', 'min:10', 'max:5000'],
                'target_role' => ['nullable', 'string', 'in:admin,vendor'],
            ]);

            $sanitizedMessage = strip_tags($validated['message'], '<p><br><strong><em><ul><li><ol>');
            $sanitizedTitle = strip_tags($validated['title']);

            if (! empty($validated['target_role'])) {
                $roleExists = Role::query()
                    ->where('name', $validated['target_role'])
                    ->exists();

                if (! $roleExists) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', 'Specified user group not found.');
                }
            }

            $notification = Notification::create([
                'title' => $sanitizedTitle,
                'message' => $sanitizedMessage,
                'target_role' => $validated['target_role'] ?? null,
                'type' => 'bulk',
            ]);

            $notification->sendToTargetUsers();

            Log::info(
                'Bulk message sent by admin ' . $request->user()->id . ' to ' . ($validated['target_role'] ?? 'all users')
            );

            return redirect()
                ->route('admin.bulk-message.list')
                ->with('success', 'Bulk message sent successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error sending bulk message: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'An error occurred while sending the message. Please try again later.');
        }
    }

    public function listBulkMessages(): RedirectResponse|View
    {
        try {
            $notifications = Notification::query()
                ->where('type', 'bulk')
                ->orderByDesc('created_at')
                ->withCount('users')
                ->paginate(16);

            foreach ($notifications as $notification) {
                if ($notification->target_role) {
                    $roleTranslations = [
                        'vendor' => 'Vendor',
                        'admin' => 'Administrator',
                    ];

                    $notification->translated_role = $roleTranslations[$notification->target_role]
                        ?? ucfirst($notification->target_role);
                }
            }

            return view('admin.bulk-message.list', compact('notifications'));
        } catch (Exception $e) {
            Log::error('Error listing bulk messages: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'An error occurred while listing messages. Please try again later.');
        }
    }

    public function deleteBulkMessage(Notification $notification): RedirectResponse
    {
        try {
            $notification->delete();

            return redirect()
                ->route('admin.bulk-message.list')
                ->with('success', 'Bulk message deleted successfully.');
        } catch (Exception $e) {
            Log::error('Error deleting bulk message: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'An error occurred while deleting the message. Please try again later.');
        }
    }

    public function popupIndex(): View
    {
        $popups = Popup::query()
            ->orderByDesc('created_at')
            ->paginate(10);

        return view('admin.pop-up.list', compact('popups'));
    }

    public function popupCreate(): View
    {
        return view('admin.pop-up.create');
    }

    public function popupStore(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'message' => ['required', 'string', 'max:5000'],
                'active' => ['sometimes', 'boolean'],
            ]);

            Popup::create([
                'title' => $validated['title'],
                'message' => $validated['message'],
                'active' => $request->boolean('active'),
            ]);

            Log::info("Pop-up created by admin {$request->user()->id}");

            return redirect()
                ->route('admin.popup.index')
                ->with('success', 'Pop-up created successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error creating pop-up: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Error creating pop-up. Please try again.');
        }
    }

    public function popupActivate(Popup $popup): RedirectResponse
    {
        try {
            $popup->update(['active' => true]);

            Log::info("Pop-up {$popup->id} activated by admin " . auth()->id());

            return redirect()
                ->route('admin.popup.index')
                ->with('success', 'Pop-up activated successfully.');
        } catch (Exception $e) {
            Log::error('Error activating pop-up: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'Error activating pop-up. Please try again.');
        }
    }

    public function popupDestroy(Popup $popup): RedirectResponse
    {
        try {
            $popup->delete();

            Log::info("Pop-up deleted by admin: {$popup->id}");

            return redirect()
                ->route('admin.popup.index')
                ->with('success', 'Pop-up deleted successfully.');
        } catch (Exception $e) {
            Log::error('Error deleting pop-up: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'Error deleting pop-up. Please try again.');
        }
    }

    public function categories(): View
    {
        $mainCategories = Category::mainCategories();

        return view('admin.categories', compact('mainCategories'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate(Category::validationRules());

            $category = Category::create([
                'name' => $validated['name'],
                'parent_id' => $validated['parent_id'] ?? null,
            ]);

            Log::info("Category created: {$category->getFormattedName()} by admin {$request->user()->id}");

            return redirect()
                ->route('admin.categories')
                ->with('success', 'Category created successfully.');
        } catch (ValidationException $e) {
            return $this->backWithFirstValidationError($e);
        } catch (Exception $e) {
            Log::error('Error creating category: ' . $e->getMessage());

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Error creating category. Please try again.');
        }
    }

    public function deleteCategory(Category $category): RedirectResponse
    {
        try {
            $categoryName = $category->getFormattedName();

            DB::beginTransaction();

            $totalProductsCount = 0;
            $deletedCategoriesCount = 0;
            $categoryIds = [];
            $productsToDelete = [];

            $collectCategoryInfo = function ($cat) use (&$collectCategoryInfo, &$totalProductsCount, &$categoryIds, &$productsToDelete): void {
                $products = $cat->products()->withTrashed()->get();
                $totalProductsCount += $products->count();
                $productsToDelete = array_merge($productsToDelete, $products->all());

                $categoryIds[] = $cat->id;

                $cat->children()->get()->each(function ($child) use ($collectCategoryInfo): void {
                    $collectCategoryInfo($child);
                });
            };

            $collectCategoryInfo($category);

            foreach ($productsToDelete as $product) {
                $product->forceDelete();
            }

            Category::query()
                ->whereIn('id', $categoryIds)
                ->orderByDesc('id')
                ->get()
                ->each(function ($cat) use (&$deletedCategoriesCount): void {
                    $cat->delete();
                    $deletedCategoriesCount++;
                });

            DB::commit();

            Log::info(
                "Category tree deleted: {$categoryName} by admin " . auth()->id() .
                ". {$totalProductsCount} products and {$deletedCategoriesCount} categories were permanently deleted."
            );

            return redirect()
                ->route('admin.categories')
                ->with(
                    'success',
                    "Category tree deleted successfully. {$totalProductsCount} products and " .
                    max($deletedCategoriesCount - 1, 0) . ' subcategories were permanently deleted.'
                );
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error deleting category: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'Error deleting category. Please try again.');
        }
    }

    public function listCategories(Request $request): JsonResponse
    {
        if (! $request->ajax()) {
            abort(404);
        }

        try {
            $categories = Category::with('parent')
                ->get()
                ->map(function ($category): array {
                    return [
                        'id' => $category->id,
                        'name' => $category->getFormattedName(),
                    ];
                });

            return response()->json($categories);
        } catch (Exception $e) {
            Log::error('Error listing categories: ' . $e->getMessage());

            return response()->json(['error' => 'Error fetching categories'], 500);
        }
    }

    public function allProducts(Request $request): RedirectResponse|View
    {
        try {
            $request->validate([
                'search' => ['nullable', 'string', 'min:1', 'max:100'],
                'vendor' => ['nullable', 'string', 'min:1', 'max:50'],
                'type' => ['nullable', Rule::in([Product::TYPE_DIGITAL, Product::TYPE_CARGO, Product::TYPE_DEADDROP])],
                'category' => ['nullable', 'integer', 'exists:categories,id'],
                'sort_price' => ['nullable', Rule::in(['asc', 'desc'])],
            ]);

            $filters = collect($request->only(['search', 'vendor', 'type', 'category', 'sort_price']))
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->toArray();

            $query = Product::query()
                ->with('user')
                ->select('products.*');

            if (isset($filters['search'])) {
                $searchTerm = strip_tags($filters['search']);
                $query->where('name', 'like', '%' . addcslashes($searchTerm, '%_') . '%');
            }

            if (isset($filters['vendor'])) {
                $vendorTerm = strip_tags($filters['vendor']);

                $query->whereHas('user', function ($q) use ($vendorTerm): void {
                    $q->where('username', 'like', '%' . addcslashes($vendorTerm, '%_') . '%');
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
                $query->latest();
            }

            $products = $query->paginate(32)->withQueryString();
            $categories = Category::query()->select('id', 'name')->get();

            return view('admin.all-products.list', [
                'products' => $products,
                'categories' => $categories,
                'currentType' => $filters['type'] ?? null,
                'filters' => $filters,
            ]);
        } catch (Exception $e) {
            Log::error('Error fetching all products: ' . $e->getMessage());

            return redirect()
                ->route('admin.index')
                ->with('error', 'Error fetching products. Please try again.');
        }
    }

    public function destroyProduct(Product $product): RedirectResponse
    {
        try {
            $product->delete();

            Log::info("Product deleted by admin: {$product->id}");

            return redirect()
                ->route('admin.all-products')
                ->with('success', 'Product deleted successfully.');
        } catch (Exception $e) {
            Log::error('Error deleting product: ' . $e->getMessage());

            return redirect()
                ->route('admin.all-products')
                ->with('error', 'Error deleting product. Please try again.');
        }
    }

    public function featureProduct(Product $product): RedirectResponse
    {
        try {
            if ($product->isFeatured()) {
                return redirect()
                    ->route('admin.all-products')
                    ->with('info', 'Product is already featured.');
            }

            FeaturedProduct::create([
                'product_id' => $product->id,
                'admin_id' => auth()->id(),
            ]);

            Log::info("Product featured by admin: {$product->id}");

            return redirect()
                ->route('admin.all-products')
                ->with('success', 'Product featured successfully.');
        } catch (Exception $e) {
            Log::error('Error featuring product: ' . $e->getMessage());

            return redirect()
                ->route('admin.all-products')
                ->with('error', 'Error featuring product. Please try again.');
        }
    }

    public function unfeatureProduct(Product $product): RedirectResponse
    {
        try {
            $featured = $product->featuredProduct;

            if (! $featured) {
                return redirect()
                    ->route('admin.all-products')
                    ->with('info', 'Product is not currently featured.');
            }

            $featured->delete();

            Log::info("Product unfeatured by admin: {$product->id}");

            return redirect()
                ->route('admin.all-products')
                ->with('success', 'Product unfeatured successfully.');
        } catch (Exception $e) {
            Log::error('Error unfeaturing product: ' . $e->getMessage());

            return redirect()
                ->route('admin.all-products')
                ->with('error', 'Error unfeaturing product. Please try again.');
        }
    }

    public function editProduct(Product $product): RedirectResponse|View
    {
        try {
            $categories = Category::with('children')->get();
            $measurementUnits = Product::getMeasurementUnits();
            $countries = $this->getCountries();

            return view('admin.all-products.edit', compact('product', 'categories', 'measurementUnits', 'countries'));
        } catch (Exception $e) {
            Log::error('Error showing product edit form: ' . $e->getMessage());

            return redirect()
                ->route('admin.all-products')
                ->with('error', 'Error loading product edit form. Please try again.');
        }
    }

    public function updateProduct(Request $request, Product $product): RedirectResponse
    {
        try {
            $countries = $this->getCountries();

            try {
                $validated = $request->validate([
                    'name' => ['required', 'string', 'max:255'],
                    'description' => ['required', 'string'],
                    'price' => ['required', 'numeric', 'min:0'],
                    'category_id' => ['required', 'exists:categories,id'],
                    'product_picture' => ['nullable', 'file', 'max:800'],
                    'additional_photos.*' => ['nullable', 'file', 'max:800'],
                    'stock_amount' => ['required', 'integer', 'min:0', 'max:999999'],
                    'measurement_unit' => ['required', Rule::in(array_keys(Product::getMeasurementUnits()))],
                    'ships_from' => ['required', 'string', Rule::in($countries)],
                    'ships_to' => ['required', 'string', Rule::in($countries)],
                ]);
            } catch (ValidationException $e) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', collect($e->errors())->flatten()->implode(' '));
            }

            $deliveryOptions = collect($request->input('delivery_options', []))
                ->map(function ($option): array {
                    return [
                        'description' => trim($option['description'] ?? ''),
                        'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                    ];
                })
                ->filter(fn ($option) => $option['description'] !== '' && is_numeric($option['price']))
                ->values()
                ->all();

            $deliveryOptionName = $product->type === Product::TYPE_DEADDROP ? 'pickup window' : 'delivery';

            if (empty($deliveryOptions)) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', "At least one {$deliveryOptionName} option is required.");
            }

            if (count($deliveryOptions) > 4) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->with('error', "No more than 4 {$deliveryOptionName} options are allowed.");
            }

            foreach ($deliveryOptions as $option) {
                if (strlen($option['description']) > 255) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', "{$deliveryOptionName} description cannot exceed 255 characters.");
                }

                if ($option['price'] < 0) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', "{$deliveryOptionName} price cannot be negative.");
                }
            }

            $bulkOptions = collect($request->input('bulk_options', []))
                ->map(function ($option): array {
                    return [
                        'amount' => is_numeric($option['amount'] ?? null) ? (float) $option['amount'] : null,
                        'price' => is_numeric($option['price'] ?? null) ? (float) $option['price'] : null,
                    ];
                })
                ->filter(fn ($option) => is_numeric($option['amount']) && is_numeric($option['price']))
                ->values()
                ->all();

            if (! empty($bulkOptions)) {
                if (count($bulkOptions) > 8) {
                    return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', 'No more than 8 bulk options are allowed.');
                }

                foreach ($bulkOptions as $option) {
                    if ($option['amount'] <= 0) {
                        return redirect()
                            ->back()
                            ->withInput()
                            ->with('error', 'Bulk amount must be greater than zero.');
                    }

                    if ($option['price'] <= 0) {
                        return redirect()
                            ->back()
                            ->withInput()
                            ->with('error', 'Bulk price must be greater than zero.');
                    }
                }
            }

            if ($request->has('delete_main_photo') && $product->product_picture !== 'default-product-picture.png') {
                Storage::disk('private')->delete('product_pictures/' . $product->product_picture);
                $product->product_picture = 'default-product-picture.png';
            }

            if ($request->has('delete_additional_photo')) {
                $index = (int) $request->input('delete_additional_photo');
                $additionalPhotos = $product->additional_photos ?? [];

                if (isset($additionalPhotos[$index])) {
                    Storage::disk('private')->delete('product_pictures/' . $additionalPhotos[$index]);
                    unset($additionalPhotos[$index]);
                    $product->additional_photos = array_values($additionalPhotos);
                }
            }

            if ($request->hasFile('product_picture')) {
                if ($product->product_picture !== 'default-product-picture.png') {
                    Storage::disk('private')->delete('product_pictures/' . $product->product_picture);
                }

                $product->product_picture = $this->handleProductPictureUpload($request->file('product_picture'));
            }

            if ($request->hasFile('additional_photos')) {
                $existingPhotos = $product->additional_photos ?? [];
                $remainingSlots = max(0, 3 - count($existingPhotos));

                foreach (array_slice($request->file('additional_photos'), 0, $remainingSlots) as $index => $photo) {
                    try {
                        $existingPhotos[] = $this->handleProductPictureUpload($photo);
                    } catch (Exception $e) {
                        Log::warning('Failed to upload additional photo: ' . $e->getMessage(), [
                            'product_id' => $product->id,
                            'photo_index' => $index,
                        ]);
                    }
                }

                $product->additional_photos = $existingPhotos;
            }

            $product->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'price' => $validated['price'],
                'category_id' => $validated['category_id'],
                'stock_amount' => $validated['stock_amount'],
                'measurement_unit' => $validated['measurement_unit'],
                'delivery_options' => $deliveryOptions,
                'bulk_options' => $bulkOptions,
                'ships_from' => $validated['ships_from'],
                'ships_to' => $validated['ships_to'],
                'active' => $request->boolean('active'),
                'product_picture' => $product->product_picture,
                'additional_photos' => $product->additional_photos,
            ]);

            $productTypeName = match ($product->type) {
                Product::TYPE_CARGO => 'Cargo',
                Product::TYPE_DIGITAL => 'Digital',
                Product::TYPE_DEADDROP => 'Dead Drop',
                default => 'Product',
            };

            Log::info("Product updated by admin: {$product->id}");

            return redirect()
                ->route('admin.all-products')
                ->with('success', "{$productTypeName} product updated successfully.");
        } catch (Exception $e) {
            Log::error('Failed to update product: ' . $e->getMessage(), [
                'product_id' => $product->id,
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Failed to update product. Please try again.');
        }
    }

    public function vendorApplications(): View
    {
        $applications = VendorPayment::query()
            ->whereNotNull('application_status')
            ->with('user')
            ->orderByDesc('application_submitted_at')
            ->paginate(20);

        return view('admin.vendor-applications.list', compact('applications'));
    }

    public function showVendorApplication(VendorPayment $application, Request $request): RedirectResponse|View|BinaryFileResponse
    {
        if (! $application->application_status) {
            return redirect()
                ->route('admin.vendor-applications.list')
                ->with('error', 'Invalid application.');
        }

        if ($request->has('image')) {
            $filename = (string) $request->query('image');
            $images = json_decode((string) $application->application_images, true) ?? [];

            if (! in_array($filename, $images, true)) {
                abort(404);
            }

            try {
                $path = 'vendor_application_pictures/' . $filename;

                if (! Storage::disk('private')->exists($path)) {
                    abort(404);
                }

                return response()->file(Storage::disk('private')->path($path));
            } catch (Exception $e) {
                Log::error('Error serving vendor application image: ' . $e->getMessage());
                abort(404);
            }
        }

        return view('admin.vendor-applications.show', compact('application'));
    }

    public function acceptVendorApplication(VendorPayment $application): RedirectResponse
    {
        if ($application->application_status !== 'waiting') {
            return redirect()
                ->route('admin.vendor-applications.show', $application)
                ->with('error', 'This application has already been processed.');
        }

        try {
            DB::beginTransaction();

            $application->update([
                'application_status' => 'accepted',
                'admin_response_at' => now(),
            ]);

            $vendorRole = Role::query()
                ->where('name', 'vendor')
                ->firstOrFail();

            $application->user->roles()->syncWithoutDetaching([$vendorRole->id]);

            DB::commit();

            Log::info("Vendor application accepted for user {$application->user_id}");

            return redirect()
                ->route('admin.vendor-applications.show', $application)
                ->with('success', 'Application accepted successfully.');
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error accepting vendor application: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'An error occurred while processing the application.');
        }
    }

    public function denyVendorApplication(VendorPayment $application): RedirectResponse
    {
        if ($application->application_status !== 'waiting') {
            return redirect()
                ->route('admin.vendor-applications.show', $application)
                ->with('error', 'This application has already been processed.');
        }

        try {
            DB::beginTransaction();

            $refundPercentage = (float) config('monero.vendor_payment_refund_percentage');

            if ($refundPercentage <= 0) {
                throw new Exception('Refund percentage must be greater than zero');
            }

            $refundAmount = (float) $application->total_received * ($refundPercentage / 100);

            $returnAddress = $application->user->returnAddresses()
                ->inRandomOrder()
                ->first();

            if (! $returnAddress) {
                throw new Exception('No return address found for user');
            }

            $application->update([
                'application_status' => 'denied',
                'admin_response_at' => now(),
            ]);

            try {
                $config = config('monero');

                $walletRPC = new walletRPC(
                    $config['host'],
                    $config['port'],
                    $config['ssl'],
                    30000
                );

                $walletRPC->transfer([
                    'address' => $returnAddress->monero_address,
                    'amount' => $refundAmount,
                    'priority' => 1,
                ]);

                $application->update([
                    'refund_amount' => $refundAmount,
                    'refund_address' => $returnAddress->monero_address,
                ]);

                $refundMessage = "Refund of {$refundAmount} XMR has been processed.";
            } catch (Exception $e) {
                Log::error('Error processing refund: ' . $e->getMessage());

                $refundMessage = 'Application denied but refund failed. Please process refund manually.';

                $application->update([
                    'refund_amount' => $refundAmount,
                    'refund_address' => $returnAddress->monero_address,
                ]);
            }

            DB::commit();

            Log::info(
                "Vendor application denied for user {$application->user_id}. Refund processed: {$refundAmount} XMR to address {$returnAddress->monero_address}"
            );

            return redirect()
                ->route('admin.vendor-applications.show', $application)
                ->with('success', 'Application denied successfully. ' . $refundMessage);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error denying vendor application: ' . $e->getMessage());

            return redirect()
                ->back()
                ->with('error', 'An error occurred while processing the application and refund.');
        }
    }

    public function statistics(): View
    {
        $totalUsers = User::count();
        $usersByRole = Role::withCount('users')->get();
        $bannedUsersCount = BannedUser::query()
            ->where('banned_until', '>', now())
            ->count();

        $totalPgpKeys = PgpKey::count();
        $verifiedPgpKeys = PgpKey::query()
            ->where('verified', true)
            ->count();
        $twoFaEnabled = PgpKey::query()
            ->where('two_fa_enabled', true)
            ->count();

        $totalProducts = Product::count();
        $productsByType = [
            'digital' => Product::query()->where('type', Product::TYPE_DIGITAL)->count(),
            'cargo' => Product::query()->where('type', Product::TYPE_CARGO)->count(),
            'deaddrop' => Product::query()->where('type', Product::TYPE_DEADDROP)->count(),
        ];

        $pgpVerificationRate = $totalUsers > 0 ? ($verifiedPgpKeys / $totalUsers) * 100 : 0;
        $twoFaAdoptionRate = $totalUsers > 0 ? ($twoFaEnabled / $totalUsers) * 100 : 0;

        return view('admin.statistics', compact(
            'totalUsers',
            'usersByRole',
            'bannedUsersCount',
            'totalPgpKeys',
            'verifiedPgpKeys',
            'twoFaEnabled',
            'pgpVerificationRate',
            'twoFaAdoptionRate',
            'totalProducts',
            'productsByType'
        ));
    }

    private function handleProductPictureUpload($file): string
    {
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());

            $allowedMimeTypes = [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ];

            if (! in_array($mimeType, $allowedMimeTypes, true)) {
                throw new Exception('Invalid file type. Allowed types are JPEG, PNG, GIF, and WebP.');
            }

            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $filename = time() . '_' . Str::uuid() . '.' . $extension;

            $manager = new ImageManager(new GdDriver());
            $image = $manager->read($file);

            if ($image->width() > 800 || $image->height() > 800) {
                $image = $image->scaleDown(width: 800, height: 800);
            }

            $encodedImage = match ($mimeType) {
                'image/png' => $image->encode(new PngEncoder()),
                'image/webp' => $image->encode(new WebpEncoder()),
                'image/gif' => $image->encode(new GifEncoder()),
                default => $image->encode(new JpegEncoder(80)),
            };

            if (! Storage::disk('private')->put('product_pictures/' . $filename, (string) $encodedImage)) {
                throw new Exception('Failed to save product picture to storage');
            }

            return $filename;
        } catch (NotReadableException $e) {
            Log::error('Image processing failed: ' . $e->getMessage());
            throw new Exception('Failed to process uploaded image. Please try a different image.');
        } catch (Exception $e) {
            Log::error('Product picture upload failed: ' . $e->getMessage());
            throw new Exception($e->getMessage());
        }
    }

    private function getCountries(): array
    {
        $path = storage_path('app/country.json');

        if (! File::exists($path)) {
            return [];
        }

        $countries = json_decode(File::get($path), true);

        return is_array($countries) ? $countries : [];
    }

    private function backWithFirstValidationError(ValidationException $e): RedirectResponse
    {
        return redirect()
            ->back()
            ->withInput()
            ->with('error', $e->validator->errors()->first());
    }
}
