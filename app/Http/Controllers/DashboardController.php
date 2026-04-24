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

use App\Models\User;
use Exception;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * Display the user dashboard.
     */
    public function index(?string $username = null): View|RedirectResponse
    {
        try {
            $loggedInUser = Auth::user();

            if (! $loggedInUser) {
                return redirect()
                ->route('login')
                ->with('error', 'Please login to access the dashboard.');
            }

            if ($username) {
                $user = User::where('username', $username)->firstOrFail();
            } else {
                $user = $loggedInUser;
            }

            $profile = $user->profile;

            if (! $profile) {
                Log::info('Creating profile for user', [
                    'user_id' => $user->id,
                ]);

                $profile = $user->profile()->create();
            }

            $pgpKey = $user->pgpKey;
            $userRole = $this->determineUserRole($user);
            $isOwnProfile = $user->id === $loggedInUser->id;
            $showFullInfo = $isOwnProfile || $loggedInUser->isAdmin();

            $description = $profile->description
            ? Crypt::decryptString($profile->description)
            : "This user hasn't added a description yet.";

            return view('dashboard', compact(
                'user',
                'profile',
                'pgpKey',
                'userRole',
                'isOwnProfile',
                'showFullInfo',
                'description'
            ));
        } catch (Exception $exception) {
            Log::error('Error loading dashboard: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->route('home')
            ->with('error', 'An error occurred while loading the dashboard. Please try again.');
        }
    }

    /**
     * Determine the user's role label.
     */
    private function determineUserRole(User $user): string
    {
        if ($user->hasRole('admin') && $user->hasRole('vendor')) {
            return 'Admin & Vendor';
        }

        if ($user->hasRole('admin')) {
            return 'Administrator';
        }

        if ($user->hasRole('vendor')) {
            return 'Vendor';
        }

        return 'Buyer';
    }
}
