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

use App\Models\Notification;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /**
     * Display the user's notifications.
     */
    public function index(): View|RedirectResponse
    {
        try {
            $user = Auth::user();

            $notifications = $user->notifications()
            ->orderBy('created_at', 'desc')
            ->paginate(16);

            return view('notifications', compact('notifications'));
        } catch (Exception $exception) {
            Log::error('Failed to load notifications: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
            ]);

            return redirect()
            ->back()
            ->with('error', 'An error occurred while loading notifications. Please try again later.');
        }
    }

    /**
     * Mark the specified notification as read.
     */
    public function markAsRead(Notification $notification): RedirectResponse
    {
        try {
            $user = Auth::user();

            $userNotification = $user->notifications()
            ->where('notifications.id', $notification->id)
            ->first();

            if (! $userNotification) {
                return redirect()
                ->route('notifications.index')
                ->with('error', 'Notification not found.');
            }

            $user->notifications()->updateExistingPivot($notification->id, [
                'read' => true,
            ]);

            return redirect()
            ->route('notifications.index')
            ->with('success', 'Notification marked as read.');
        } catch (Exception $exception) {
            Log::error('Failed to mark notification as read: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'notification_id' => $notification->id,
            ]);

            return redirect()
            ->route('notifications.index')
            ->with('error', 'Failed to mark notification as read: '.$exception->getMessage());
        }
    }

    /**
     * Remove the specified notification from the user's list.
     */
    public function destroy(Notification $notification): RedirectResponse
    {
        try {
            $user = Auth::user();

            $userNotification = $user->notifications()
            ->where('notifications.id', $notification->id)
            ->first();

            if (! $userNotification) {
                return redirect()
                ->route('notifications.index')
                ->with('error', 'Notification not found.');
            }

            $user->notifications()->detach($notification->id);

            return redirect()
            ->route('notifications.index')
            ->with('success', 'Notification deleted successfully.');
        } catch (Exception $exception) {
            Log::error('Failed to delete notification: '.$exception->getMessage(), [
                'user_id' => Auth::id(),
                       'notification_id' => $notification->id,
            ]);

            return redirect()
            ->route('notifications.index')
            ->with('error', 'Failed to delete notification: '.$exception->getMessage());
        }
    }
}
