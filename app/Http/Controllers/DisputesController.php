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
use App\Models\DisputeMessage;
use App\Models\Orders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DisputesController extends Controller
{
    /**
     * Display a listing of disputes for the current user.
     */
    public function index(): View
    {
        $disputes = Dispute::getUserDisputes(Auth::id());

        return view('disputes.index', [
            'disputes' => $disputes,
        ]);
    }

    /**
     * Display the specified dispute.
     */
    public function show(int $id): View
    {
        $dispute = Dispute::with(['order', 'messages.user'])->findOrFail($id);

        $user = Auth::user();
        $isBuyer = $dispute->order->user_id === $user->id;
        $isVendor = $dispute->order->vendor_id === $user->id;
        $isAdmin = $user->hasRole('admin');

        if (! $isBuyer && ! $isVendor && ! $isAdmin) {
            abort(403, 'Unauthorized access.');
        }

        return view('disputes.show', [
            'dispute' => $dispute,
            'isBuyer' => $isBuyer,
            'isVendor' => $isVendor,
            'isAdmin' => $isAdmin,
        ]);
    }

    /**
     * Open a dispute for an order.
     */
    public function store(Request $request, string $uniqueUrl): RedirectResponse
    {
        $order = Orders::findByUrl($uniqueUrl);

        if (! $order) {
            abort(404);
        }

        if ($order->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:8|max:1600',
        ]);

        $dispute = $order->openDispute($validated['reason']);

        if (! $dispute) {
            return redirect()
            ->route('orders.show', $order->unique_url)
            ->with('error', 'Unable to open dispute. Disputes can only be opened for orders with "Product Sent" status.');
        }

        $message = new DisputeMessage([
            'dispute_id' => $dispute->id,
            'user_id' => Auth::id(),
                                      'message' => $validated['reason'],
        ]);

        $message->save();

        return redirect()
        ->route('disputes.show', $dispute->id)
        ->with('success', 'Dispute opened successfully.');
    }

    /**
     * Add a message to a dispute.
     */
    public function addMessage(Request $request, int $id): RedirectResponse
    {
        $dispute = Dispute::with('order')->findOrFail($id);

        $user = Auth::user();
        $isBuyer = $dispute->order->user_id === $user->id;
        $isVendor = $dispute->order->vendor_id === $user->id;
        $isAdmin = $user->hasRole('admin');

        if (! $isBuyer && ! $isVendor && ! $isAdmin) {
            abort(403, 'Unauthorized action.');
        }

        if ($dispute->status !== Dispute::STATUS_ACTIVE) {
            return redirect()
            ->route('disputes.show', $dispute->id)
            ->with('error', 'Cannot add messages to a resolved dispute.');
        }

        $validated = $request->validate([
            'message' => 'required|string|min:4|max:800',
        ]);

        $message = new DisputeMessage([
            'dispute_id' => $dispute->id,
            'user_id' => Auth::id(),
                                      'message' => $validated['message'],
        ]);

        $message->save();

        if ($isAdmin) {
            return redirect()
            ->route('admin.disputes.show', $dispute->id)
            ->with('success', 'Message added successfully.');
        }

        if ($isVendor) {
            return redirect()
            ->route('vendor.disputes.show', $dispute->id)
            ->with('success', 'Message added successfully.');
        }

        return redirect()
        ->route('disputes.show', $dispute->id)
        ->with('success', 'Message added successfully.');
    }

    /**
     * Display a listing of all disputes for admin.
     */
    public function adminIndex(): View
    {
        $disputes = Dispute::orderBy('created_at', 'desc')->get();

        return view('admin.disputes.index', [
            'disputes' => $disputes,
        ]);
    }

    /**
     * Display a specific dispute for admin.
     */
    public function adminShow(int $id): View
    {
        $dispute = Dispute::with(['order', 'order.user', 'order.vendor', 'messages.user'])->findOrFail($id);

        return view('admin.disputes.show', [
            'dispute' => $dispute,
        ]);
    }

    /**
     * Resolve a dispute with vendor prevailing.
     */
    public function resolveVendorPrevails(Request $request, int $id): RedirectResponse
    {
        $dispute = Dispute::findOrFail($id);

        if ($dispute->status !== Dispute::STATUS_ACTIVE) {
            return redirect()
            ->route('admin.disputes.show', $dispute->id)
            ->with('error', 'This dispute has already been resolved.');
        }

        if ($dispute->resolveVendorPrevails(Auth::id())) {
            if ($request->has('message') && ! empty($request->message)) {
                $message = new DisputeMessage([
                    'dispute_id' => $dispute->id,
                    'user_id' => Auth::id(),
                                              'message' => $request->message,
                ]);

                $message->save();
            }

            return redirect()
            ->route('admin.disputes.show', $dispute->id)
            ->with('success', 'Dispute resolved in favor of the vendor. Payment has been sent to the vendor.');
        }

        return redirect()
        ->route('admin.disputes.show', $dispute->id)
        ->with('error', 'Unable to resolve dispute.');
    }

    /**
     * Resolve a dispute with buyer prevailing.
     */
    public function resolveBuyerPrevails(Request $request, int $id): RedirectResponse
    {
        $dispute = Dispute::findOrFail($id);

        if ($dispute->status !== Dispute::STATUS_ACTIVE) {
            return redirect()
            ->route('admin.disputes.show', $dispute->id)
            ->with('error', 'This dispute has already been resolved.');
        }

        if ($dispute->resolveBuyerPrevails(Auth::id())) {
            if ($request->has('message') && ! empty($request->message)) {
                $message = new DisputeMessage([
                    'dispute_id' => $dispute->id,
                    'user_id' => Auth::id(),
                                              'message' => $request->message,
                ]);

                $message->save();
            }

            return redirect()
            ->route('admin.disputes.show', $dispute->id)
            ->with('success', 'Dispute resolved in favor of the buyer.');
        }

        return redirect()
        ->route('admin.disputes.show', $dispute->id)
        ->with('error', 'Unable to resolve dispute.');
    }

    /**
     * Display a listing of disputes for a vendor.
     */
    public function vendorDisputes(): View
    {
        $disputes = Dispute::getVendorDisputes(Auth::id());

        return view('vendor.disputes.index', [
            'disputes' => $disputes,
        ]);
    }

    /**
     * Display a specific dispute for a vendor.
     */
    public function vendorShow(int $id): View
    {
        $dispute = Dispute::with(['order', 'order.user', 'messages.user'])->findOrFail($id);

        if ($dispute->order->vendor_id !== Auth::id()) {
            abort(403, 'Unauthorized access.');
        }

        return view('vendor.disputes.show', [
            'dispute' => $dispute,
        ]);
    }
}
