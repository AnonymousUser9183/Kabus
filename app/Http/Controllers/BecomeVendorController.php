<?php

/*
 * |--------------------------------------------------------------------------
 * | Copyright Notice
 * |--------------------------------------------------------------------------
 * | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 * | Original Kabus Marketplace Script created by Sukunetsiz.
 * |--------------------------------------------------------------------------
 */

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VendorPayment;
use Carbon\Carbon;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Exception;
use finfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\NotReadableException;
use Intervention\Image\ImageManager;
use MoneroIntegrations\MoneroPhp\walletRPC;

class BecomeVendorController extends Controller
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
     * Show vendor application landing page.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $hasPgpVerified = false;
        $hasMoneroAddress = false;

        if ($user->pgpKey) {
            $hasPgpVerified = $user->pgpKey->verified;
        }

        $hasMoneroAddress = $user->returnAddresses()->exists();

        $vendorPayment = VendorPayment::where('user_id', $user->id)
        ->orderBy('created_at', 'desc')
        ->first();

        return view('become-vendor.index', compact(
            'hasPgpVerified',
            'hasMoneroAddress',
            'vendorPayment'
        ));
    }

    /**
     * Show vendor payment page.
     */
    public function payment(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $hasPgpVerified = false;
        $hasMoneroAddress = false;

        if ($user->pgpKey) {
            $hasPgpVerified = $user->pgpKey->verified;
        }

        $hasMoneroAddress = $user->returnAddresses()->exists();

        $existingPayment = VendorPayment::where('user_id', $user->id)
        ->whereNotNull('application_status')
        ->first();

        if ($existingPayment) {
            return redirect()
            ->route('become.vendor')
            ->with('info', 'You already have a processed vendor application.');
        }

        if ($user->isVendor()) {
            return view('become-vendor.payment', [
                'alreadyVendor' => true,
                'hasPgpVerified' => $hasPgpVerified,
                'hasMoneroAddress' => $hasMoneroAddress,
            ]);
        }

        try {
            $vendorPayment = $this->getCurrentVendorPayment($user);
            $qrCodeDataUri = $vendorPayment ? $this->generateQrCode($vendorPayment->address) : null;

            return view('become-vendor.payment', [
                'vendorPayment' => $vendorPayment,
                'qrCodeDataUri' => $qrCodeDataUri,
                'hasPgpVerified' => $hasPgpVerified,
                'hasMoneroAddress' => $hasMoneroAddress,
            ]);
        } catch (Exception $exception) {
            Log::error('Error in payment process: '.$exception->getMessage());

            return view('become-vendor.payment', [
                'error' => 'An error occurred while processing your payment. Please try again later.',
                'hasPgpVerified' => $hasPgpVerified,
                'hasMoneroAddress' => $hasMoneroAddress,
            ]);
        }
    }

    /**
     * Get current vendor payment or create a new one.
     */
    private function getCurrentVendorPayment(User $user): VendorPayment
    {
        try {
            $vendorPayment = VendorPayment::where('user_id', $user->id)
            ->where('expires_at', '>', Carbon::now())
            ->orderBy('created_at', 'desc')
            ->first();

            if ($vendorPayment) {
                $this->checkIncomingTransaction($vendorPayment);

                return $vendorPayment->fresh();
            }

            return $this->createVendorPayment($user);
        } catch (Exception $exception) {
            Log::error('Error getting current vendor payment: '.$exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Create a new vendor payment address.
     */
    private function createVendorPayment(User $user): VendorPayment
    {
        if (! $this->walletRPC) {
            throw new Exception('Wallet RPC is not available.');
        }

        try {
            $result = $this->walletRPC->create_address(
                0,
                'Vendor Payment '.$user->id.'_'.time()
            );

            $vendorPayment = new VendorPayment([
                'address' => $result['address'],
                'address_index' => $result['address_index'],
                'user_id' => $user->id,
                'expires_at' => Carbon::now()->addMinutes((int) config('monero.address_expiration_time')),
            ]);

            $vendorPayment->save();

            Log::info('Created new vendor payment address for user '.$user->id);

            return $vendorPayment;
        } catch (Exception $exception) {
            Log::error('Error creating Monero subaddress: '.$exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Check incoming transaction for vendor payment.
     */
    private function checkIncomingTransaction(VendorPayment $vendorPayment): void
    {
        if (! $this->walletRPC) {
            throw new Exception('Wallet RPC is not available.');
        }

        try {
            $config = config('monero');

            $transfers = $this->walletRPC->get_transfers([
                'in' => true,
                'pool' => true,
                'subaddr_indices' => [$vendorPayment->address_index],
            ]);

            $totalReceived = 0;

            foreach (['in', 'pool'] as $type) {
                if (! isset($transfers[$type])) {
                    continue;
                }

                foreach ($transfers[$type] as $transfer) {
                    if ($transfer['amount'] >= $config['vendor_payment_minimum_amount'] * 1e12) {
                        $totalReceived += $transfer['amount'] / 1e12;
                    }
                }
            }

            $vendorPayment->total_received = $totalReceived;
            $vendorPayment->save();

            if (
                $totalReceived >= $config['vendor_payment_required_amount'] &&
                ! $vendorPayment->payment_completed
            ) {
                $vendorPayment->payment_completed = true;
                $vendorPayment->save();
            }

            Log::info(
                'Updated incoming transaction for user '.$vendorPayment->user_id.
                '. Total received: '.$totalReceived
            );
        } catch (Exception $exception) {
            Log::error('Error checking incoming Monero transaction: '.$exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Show vendor application form.
     */
    public function showApplication(): View|RedirectResponse
    {
        $processedApplication = VendorPayment::where('user_id', auth()->id())
        ->whereNotNull('application_status')
        ->first();

        if ($processedApplication) {
            return redirect()
            ->route('become.vendor')
            ->with('info', 'You already have a processed vendor application.');
        }

        $vendorPayment = VendorPayment::where('user_id', auth()->id())
        ->where('payment_completed', true)
        ->whereNull('application_status')
        ->first();

        if (! $vendorPayment) {
            return redirect()
            ->route('become.vendor')
            ->with('error', 'You must complete the payment before submitting an application.');
        }

        return view('become-vendor.application', compact('vendorPayment'));
    }

    /**
     * Submit vendor application.
     */
    public function submitApplication(Request $request): RedirectResponse
    {
        $processedApplication = VendorPayment::where('user_id', auth()->id())
        ->whereNotNull('application_status')
        ->first();

        if ($processedApplication) {
            return redirect()
            ->route('become.vendor')
            ->with('info', 'You already have a processed vendor application.');
        }

        $vendorPayment = VendorPayment::where('user_id', auth()->id())
        ->where('payment_completed', true)
        ->whereNull('application_status')
        ->first();

        if (! $vendorPayment) {
            return redirect()
            ->route('become.vendor')
            ->with('error', 'You must complete the payment before submitting an application.');
        }

        $request->validate([
            'application_text' => 'required|string|min:80|max:4000',
            'product_images' => ['required', 'array', 'min:1', 'max:4'],
            'product_images.*' => [
                'required',
                'file',
                'image',
                'max:800',
                'mimes:jpeg,png,gif,webp',
            ],
        ]);

        try {
            $images = [];

            if ($request->hasFile('product_images')) {
                foreach ($request->file('product_images') as $image) {
                    try {
                        $images[] = $this->handleApplicationPictureUpload($image);
                    } catch (Exception $exception) {
                        foreach ($images as $uploadedImage) {
                            Storage::disk('private')->delete('vendor_application_pictures/'.$uploadedImage);
                        }

                        Log::error('Failed to upload application image: '.$exception->getMessage());

                        return redirect()
                        ->back()
                        ->withInput()
                        ->with('error', 'Failed to upload images. Please try again.');
                    }
                }
            }

            try {
                $vendorPayment->update([
                    'application_text' => $request->application_text,
                    'application_images' => json_encode($images),
                                       'application_status' => 'waiting',
                                       'application_submitted_at' => now(),
                ]);
            } catch (Exception $exception) {
                foreach ($images as $image) {
                    Storage::disk('private')->delete('vendor_application_pictures/'.$image);
                }

                throw $exception;
            }

            Log::info("Vendor application submitted for user {$vendorPayment->user_id}");

            return redirect()
            ->route('become.vendor')
            ->with('success', 'Your application has been submitted successfully and is now under review.');
        } catch (Exception $exception) {
            Log::error('Error submitting vendor application: '.$exception->getMessage());

            return redirect()
            ->back()
            ->withInput()
            ->with('error', 'An error occurred while submitting your application. Please try again.');
        }
    }

    /**
     * Handle vendor application picture upload.
     */
    private function handleApplicationPictureUpload($file): string
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

            $filename = time().'_'.Str::uuid().'.'.$extension;

            $manager = new ImageManager(new GdDriver());

            $image = $manager->read($file)->scaleDown(width: 800, height: 800);

            $encodedImage = match ($mimeType) {
                'image/png' => $image->encode(new PngEncoder()),
                'image/webp' => $image->encode(new WebpEncoder()),
                'image/gif' => $image->encode(new GifEncoder()),
                default => $image->encode(new JpegEncoder(80)),
            };

            if (! Storage::disk('private')->put('vendor_application_pictures/'.$filename, (string) $encodedImage)) {
                throw new Exception('Failed to save application picture to storage');
            }

            return $filename;
        } catch (NotReadableException $exception) {
            Log::error('Image processing failed: '.$exception->getMessage());
            throw new Exception('Failed to process uploaded image. Please try a different image.');
        } catch (Exception $exception) {
            Log::error('Application picture upload failed: '.$exception->getMessage());
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * Generate QR code for payment address.
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
}
