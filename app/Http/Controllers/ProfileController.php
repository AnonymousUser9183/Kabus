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

use App\Models\Profile;
use Carbon\Carbon;
use Exception;
use finfo;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Exceptions\NotReadableException;
use Intervention\Image\ImageManager;

class ProfileController extends Controller
{
    private const CONFIRMATION_EXPIRY_MINUTES = 10;

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
        $this->middleware('auth');
    }

    /**
     * Show the user's profile settings page.
     */
    public function index(): View
    {
        $user = Auth::user();
        $profile = $user->profile ?? $user->profile()->create();

        return view('profile.index', compact('user', 'profile'));
    }

    /**
     * Update the user's profile.
     */
    public function update(Request $request): RedirectResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'description' => [
                    'required',
                    'string',
                    'min:4',
                    'max:800',
                    'regex:/^[\p{L}\p{N}\s\p{P}]+$/u',
                ],
                'profile_picture' => [
                    'nullable',
                    'file',
                    'max:800',
                ],
            ], [
                'description.required' => 'Description is required.',
                'description.min' => 'Description must be at least 4 characters.',
                'description.max' => 'Description cannot exceed 800 characters.',
                'description.regex' => 'Description can only contain letters, numbers, spaces, and punctuation marks.',
                'profile_picture.max' => 'Profile picture must not be larger than 800KB.',
            ]);

            if ($validator->fails()) {
                return redirect()
                ->route('profile')
                ->with('error', $validator->errors()->first())
                ->withInput();
            }

            $user = Auth::user();
            $profile = $user->profile ?? $user->profile()->create();

            $profile->description = Crypt::encryptString($request->description);

            if ($request->hasFile('profile_picture')) {
                $this->handleProfilePictureUpload($request->file('profile_picture'), $profile);
            }

            $profile->save();

            return redirect()
            ->route('profile')
            ->with('success', 'Profile successfully updated.');
        } catch (Exception $exception) {
            Log::error('Profile update failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->route('profile')
            ->with('error', 'An error occurred while updating your profile. Please try again.');
        }
    }

    /**
     * Delete the user's profile picture.
     */
    public function deleteProfilePicture(): RedirectResponse
    {
        try {
            $user = Auth::user();
            $profile = $user->profile;

            if ($profile && $profile->profile_picture) {
                if (! Storage::disk('private')->delete('profile_pictures/'.$profile->profile_picture)) {
                    throw new Exception('Failed to delete profile picture from storage');
                }

                $profile->profile_picture = null;
                $profile->save();
            }

            return redirect()
            ->route('profile')
            ->with('success', 'Profile picture successfully deleted.');
        } catch (Exception $exception) {
            Log::error('Profile picture deletion failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->route('profile')
            ->with('error', 'An error occurred while deleting your profile picture. Please try again.');
        }
    }

    /**
     * Handle the profile picture upload.
     */
    private function handleProfilePictureUpload($file, $profile): void
    {
        try {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file->getPathname());

            if (! in_array($mimeType, $this->allowedMimeTypes, true)) {
                throw new Exception('Invalid file type. Allowed types are JPEG, PNG, GIF, and WebP.');
            }

            if ($profile->profile_picture) {
                if (! Storage::disk('private')->delete('profile_pictures/'.$profile->profile_picture)) {
                    throw new Exception('Failed to delete old profile picture from storage');
                }
            }

            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = time().'_'.Str::uuid().'.'.$extension;

            $manager = new ImageManager(new GdDriver());

            $image = $manager->read($file)->scaleDown(width: 160, height: 160);

            $encodedImage = $this->encodeImage($image, $mimeType);

            if (! Storage::disk('private')->put('profile_pictures/'.$filename, (string) $encodedImage)) {
                throw new Exception('Failed to save profile picture to storage');
            }

            $profile->profile_picture = $filename;
        } catch (NotReadableException $exception) {
            Log::error('Image processing failed: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            throw new Exception('Could not process the uploaded image. Please try a different image.');
        } catch (Exception $exception) {
            Log::error('Profile picture upload failed: '.$exception->getMessage(), [
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
     * Serve a profile picture from private storage.
     */
    public function getProfilePicture(string $filename): Response
    {
        try {
            if (! Auth::check()) {
                abort(403, 'Unauthorized action.');
            }

            $path = 'profile_pictures/'.$filename;

            if (! Storage::disk('private')->exists($path)) {
                throw new Exception('Profile picture not found');
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
            Log::error('Failed to retrieve profile picture: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'filename' => $filename,
            ]);

            abort(404, 'Profile picture not found');
        }
    }

    /**
     * Show PGP key confirmation form.
     */
    public function showPgpConfirmationForm(): View|RedirectResponse
    {
        $user = Auth::user();
        $pgpKey = $user->pgpKey;

        if (! $pgpKey || $pgpKey->verified) {
            return redirect()
            ->route('profile')
            ->with('info', 'You do not have an unverified PGP key to confirm.');
        }

        Log::info('User ID: '.$user->id);
        Log::info('PGP Key ID: '.$pgpKey->id);
        Log::info('PGP Key User ID: '.$pgpKey->user_id);

        $message = 'PGP-'.mt_rand(1000000000, 9999999999).'-KEY';
        $encryptedMessage = '';

        $tempDir = sys_get_temp_dir().'/gnupg_'.uniqid('', true);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }

        try {
            putenv('GNUPGHOME='.$tempDir);

            $gpg = new \gnupg();
            $gpg->seterrormode(\gnupg::ERROR_EXCEPTION);

            $importInfo = $gpg->import($pgpKey->public_key);
            Log::info('Import info: '.json_encode($importInfo));

            if (empty($importInfo['fingerprint'])) {
                throw new Exception('Failed to import the public key. No fingerprint returned.');
            }

            $gpg->addencryptkey($importInfo['fingerprint']);
            $encryptedMessage = $gpg->encrypt($message);

            $expirationTime = Carbon::now()->addMinutes(self::CONFIRMATION_EXPIRY_MINUTES);

            session([
                'pgp_confirmation_message' => $message,
                'pgp_confirmation_expiry' => $expirationTime,
            ]);

            return view('profile.confirm-pgp-key', compact('encryptedMessage', 'expirationTime'));
        } catch (Exception $exception) {
            Log::error('Error in PGP process: '.$exception->getMessage());
            Log::error('Exception trace: '.$exception->getTraceAsString());

            return redirect()
            ->route('profile')
            ->with('error', 'An error occurred while processing your PGP key. Please try again or contact support if the problem persists.');
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Confirm PGP key.
     */
    public function confirmPgpKey(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $pgpKey = $user->pgpKey;

        if (! $pgpKey || $pgpKey->verified) {
            return redirect()
            ->route('profile')
            ->with('info', 'You do not have an unverified PGP key to confirm.');
        }

        $validator = Validator::make($request->all(), [
            'decrypted_message' => [
                'required',
                'string',
                'regex:/^PGP-\d{10}-KEY$/',
            ],
        ], [
            'decrypted_message.required' => 'The decrypted message is required.',
            'decrypted_message.regex' => 'The decrypted message must be in the correct format (PGP-{number}-KEY).',
        ]);

        if ($validator->fails()) {
            return back()
            ->with('error', $validator->errors()->first())
            ->withInput();
        }

        $originalMessage = session('pgp_confirmation_message');
        $expirationTime = session('pgp_confirmation_expiry');
        $decryptedMessage = $request->input('decrypted_message');

        if (! $originalMessage || ! $expirationTime) {
            return redirect()
            ->route('pgp.confirm')
            ->with('error', 'Verification session is missing or expired. Please try again.');
        }

        $expiration = $expirationTime instanceof Carbon
        ? $expirationTime
        : Carbon::parse($expirationTime);

        if (Carbon::now()->isAfter($expiration)) {
            return redirect()
            ->route('pgp.confirm')
            ->with('error', 'Verification process has timed out. Please try again.');
        }

        if ($decryptedMessage !== $originalMessage) {
            return back()
            ->with('error', 'The decrypted message does not match. Please try again.')
            ->withInput();
        }

        try {
            $pgpKey->verified = true;
            $pgpKey->save();

            session()->forget([
                'pgp_confirmation_message',
                'pgp_confirmation_expiry',
            ]);

            return redirect()
            ->route('profile')
            ->with('success', 'Your PGP key has been successfully verified.');
        } catch (QueryException $exception) {
            Log::error('Error saving PGP key verification status: '.$exception->getMessage());

            return redirect()
            ->route('profile')
            ->with('error', 'An error occurred while verifying your PGP key. Please try again or contact support if the problem persists.');
        }
    }

    /**
     * Clean up temporary directory.
     */
    private function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $objects = scandir($dir);

        if ($objects === false) {
            return;
        }

        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir.'/'.$object;

            if (is_dir($path)) {
                $this->cleanupTempDir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
