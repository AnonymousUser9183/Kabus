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

use App\Models\ReturnAddress;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use MoneroIntegrations\MoneroPhp\cryptonote;

class ReturnAddressController extends Controller
{
    protected ?cryptonote $cryptonote = null;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        try {
            $this->cryptonote = new cryptonote();
        } catch (Exception $exception) {
            Log::error('Failed to initialize Monero cryptonote: '.$exception->getMessage());
            $this->cryptonote = null;
        }
    }

    /**
     * Display a listing of the resource and the form to add a new address.
     */
    public function index(): View
    {
        $returnAddresses = Auth::user()->returnAddresses;

        return view('return-addresses', compact('returnAddresses'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'monero_address' => [
                'required',
                'string',
                'min:40',
                'max:160',
                Rule::unique('return_addresses')->where(function ($query) {
                    return $query->where('user_id', Auth::id());
                }),
            ],
        ], [
            'monero_address.required' => 'Monero address is required.',
            'monero_address.min' => 'Monero address must be at least 40 characters.',
            'monero_address.max' => 'Monero address may not exceed 160 characters.',
            'monero_address.unique' => 'This return address has already been added.',
        ]);

        if ($validator->fails()) {
            return redirect()
            ->route('return-addresses.index')
            ->with('error', $validator->errors()->first())
            ->withInput();
        }

        if (! $this->cryptonote) {
            return redirect()
            ->route('return-addresses.index')
            ->with('error', 'Monero address validation service is currently unavailable. Please try again later.')
            ->withInput();
        }

        $isValid = false;

        try {
            $isValid = $this->cryptonote->verify_checksum($request->monero_address);

            if ($isValid) {
                $this->cryptonote->decode_address($request->monero_address);
                $isValid = true;
            }
        } catch (Exception $exception) {
            Log::error('Monero address validation error: '.$exception->getMessage());
            $isValid = false;
        }

        if (! $isValid) {
            return redirect()
            ->route('return-addresses.index')
            ->with('error', 'Invalid Monero address. Please try again.')
            ->withInput();
        }

        $user = Auth::user();

        if ($user->returnAddresses()->count() >= 8) {
            return redirect()
            ->route('return-addresses.index')
            ->with('error', 'You can add a maximum of 8 return addresses.');
        }

        ReturnAddress::create([
            'user_id' => $user->id,
            'monero_address' => $request->monero_address,
        ]);

        return redirect()
        ->route('return-addresses.index')
        ->with('success', 'Return address successfully added.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ReturnAddress $returnAddress): RedirectResponse
    {
        if ($returnAddress->user_id !== Auth::id()) {
            abort(403, 'Unauthorized action.');
        }

        $returnAddress->delete();

        return redirect()
        ->route('return-addresses.index')
        ->with('success', 'Return address successfully deleted.');
    }
}
