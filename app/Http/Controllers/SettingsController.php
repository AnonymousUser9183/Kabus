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

use App\Models\PgpKey;
use App\Models\SecretPhrase;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index(): View|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('home')->with('error', 'Unauthorized access.');
        }

        return view('settings.index', [
            'user' => Auth::user(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        if (! Auth::check()) {
            return back()->with('error', 'Unauthorized access.');
        }

        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', 'min:8', 'max:40'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:40',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[#$%&@^`~.,:;"\'\/|_\-<>*+!?={\[\]()\}\]])[A-Za-z\d#$%&@^`~.,:;"\'\/|_\-<>*+!?={\[\]()\}\]]{8,}$/',
            ],
            'password_confirmation' => ['required', 'string'],
        ], [
            'current_password.required' => 'Current password is required.',
            'current_password.min' => 'Current password must be at least 8 characters.',
            'current_password.max' => 'Current password cannot exceed 40 characters.',
            'password.required' => 'New password is required.',
            'password.string' => 'New password must be a string.',
            'password.min' => 'New password must be at least 8 characters.',
            'password.max' => 'New password cannot exceed 40 characters.',
            'password.confirmed' => 'New password confirmation does not match.',
            'password.regex' => 'New password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
            'password_confirmation.required' => 'Please confirm your new password.',
            'password_confirmation.string' => 'Password confirmation must be a string.',
        ]);

        $validator->after(function ($validator) use ($request) {
            $user = Auth::user();

            if (! $user || ! Hash::check($request->current_password, $user->password)) {
                $validator->errors()->add('current_password', 'The entered password does not match your current password.');
            }
        });

        if ($validator->fails()) {
            return back()
            ->withInput()
            ->with('error', $validator->errors()->first());
        }

        $user = Auth::user();

        try {
            $user->password = Hash::make($request->password);
            $user->save();

            Log::info("User {$user->id} changed their password");

            $request->session()->regenerate();

            return back()->with('status', 'Password successfully changed. Your session has been renewed for security purposes.');
        } catch (QueryException $exception) {
            Log::error("Error changing password for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An error occurred while changing your password. Please try again or contact support if the problem persists.');
        } catch (Exception $exception) {
            Log::error("Unexpected error changing password for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An unexpected error occurred. Please try again or contact support if the problem persists.');
        }
    }

    /**
     * Update the user's PGP key.
     */
    public function updatePgpKey(Request $request): RedirectResponse
    {
        if (! Auth::check()) {
            return back()->with('error', 'Unauthorized access.');
        }

        $validator = Validator::make($request->all(), [
            'public_key' => [
                'required',
                'string',
                'min:100',
                'max:8000',
                'regex:/^-----BEGIN PGP PUBLIC KEY BLOCK-----.*-----END PGP PUBLIC KEY BLOCK-----$/s',
            ],
        ], [
            'public_key.required' => 'PGP public key is required.',
            'public_key.min' => 'PGP public key must be at least 100 characters.',
            'public_key.max' => 'PGP public key must not exceed 8000 characters.',
            'public_key.regex' => 'PGP public key must be in the correct format.',
        ]);

        if ($validator->fails()) {
            return back()
            ->withInput()
            ->with('error', $validator->errors()->first());
        }

        $user = Auth::user();
        $pgpKey = $user->pgpKey ?? new PgpKey();

        try {
            $pgpKey->user_id = $user->id;
            $pgpKey->public_key = $request->public_key;
            $pgpKey->verified = false;
            $pgpKey->save();

            Log::info("User {$user->id} updated their PGP key");

            return redirect()
            ->route('profile')
            ->with('status', 'PGP key successfully updated. Please verify your new PGP key.');
        } catch (QueryException $exception) {
            Log::error("Error updating PGP key for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An error occurred while updating your PGP key. Please try again or contact support if the problem persists.');
        } catch (Exception $exception) {
            Log::error("Unexpected error updating PGP key for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An unexpected error occurred. Please try again or contact support if the problem persists.');
        }
    }

    /**
     * Update the user's secret phrase.
     */
    public function updateSecretPhrase(Request $request): RedirectResponse
    {
        if (! Auth::check()) {
            return back()->with('error', 'Unauthorized access.');
        }

        $validator = Validator::make($request->all(), [
            'secret_phrase' => [
                'required',
                'string',
                'min:4',
                'max:16',
                'regex:/^[a-zA-Z]+$/',
            ],
        ], [
            'secret_phrase.required' => 'Secret phrase is required.',
            'secret_phrase.min' => 'Secret phrase must be at least 4 characters.',
            'secret_phrase.max' => 'Secret phrase cannot exceed 16 characters.',
            'secret_phrase.regex' => 'Secret phrase must contain only letters (no numbers or special characters).',
        ]);

        if ($validator->fails()) {
            return back()
            ->withInput()
            ->with('error', $validator->errors()->first());
        }

        $user = Auth::user();

        try {
            if ($user->secretPhrase) {
                return back()->with('error', 'You already have a secret phrase. This is a one-time setting for security purposes.');
            }

            $secretPhrase = new SecretPhrase();
            $secretPhrase->user_id = $user->id;
            $secretPhrase->phrase = $request->secret_phrase;
            $secretPhrase->save();

            Log::info("User {$user->id} added a secret phrase");

            return back()->with('status', 'Secret phrase successfully added. This phrase will be displayed on your settings page to help you identify genuine site access.');
        } catch (QueryException $exception) {
            Log::error("Error adding secret phrase for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An error occurred while adding your secret phrase. Please try again or contact support if the problem persists.');
        } catch (Exception $exception) {
            Log::error("Unexpected error adding secret phrase for user {$user->id}: ".$exception->getMessage());

            return back()->with('error', 'An unexpected error occurred. Please try again or contact support if the problem persists.');
        }
    }
}
