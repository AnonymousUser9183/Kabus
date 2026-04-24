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

use App\Models\SupportRequest;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class SupportController extends Controller
{
    protected $captchaService;

    /**
     * Create a new controller instance.
     */
    public function __construct($captchaService)
    {
        $this->captchaService = $captchaService;
    }

    /**
     * Display the user's support requests.
     */
    public function index(): View|RedirectResponse
    {
        try {
            $requests = SupportRequest::mainRequests()
            ->where('user_id', Auth::id())
            ->with('latestMessage')
            ->orderBy('created_at', 'desc')
            ->paginate(4);

            return view('support.index', compact('requests'));
        } catch (QueryException $exception) {
            Log::error('Support requests cannot be loaded.', [
                'user_id' => Auth::id(),
                       'message' => $exception->getMessage(),
            ]);

            return redirect()
            ->route('home')
            ->with('error', 'Support requests cannot be loaded. Please try again later.');
        }
    }

    /**
     * Show the form for creating a new support request.
     */
    public function create(): View
    {
        $captchaCode = $this->captchaService->generateCode();
        $captchaImage = $this->captchaService->generateImage($captchaCode);

        session(['captcha_code' => $captchaCode]);

        return view('support.create', [
            'captchaCode' => $captchaCode,
            'captchaImage' => $captchaImage,
        ]);
    }

    /**
     * Store a newly created support request.
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|min:8|max:160',
                'message' => 'required|string|min:8|max:4000',
                'captcha' => 'required|string|min:2|max:8',
            ]);

            if ($validator->fails()) {
                return back()
                ->with('error', $validator->errors()->first())
                ->withInput();
            }

            $captchaCode = session('captcha_code');

            if (! $captchaCode || ! hash_equals(strtoupper($captchaCode), strtoupper($request->captcha))) {
                return back()
                ->with('error', 'Invalid CAPTCHA code.')
                ->withInput();
            }

            session()->forget('captcha_code');

            $supportRequest = SupportRequest::create([
                'user_id' => Auth::id(),
                                                     'title' => $request->title,
                                                     'status' => 'open',
            ]);

            $supportRequest->messages()->create([
                'user_id' => Auth::id(),
                                                'message' => $request->message,
                                                'is_admin_reply' => false,
            ]);

            Log::info("New support ticket created: {$supportRequest->ticket_id} by user {$supportRequest->user_id}");

            return redirect()
            ->route('support.show', $supportRequest->ticket_id)
            ->with('success', 'Support request successfully created.');
        } catch (Exception $exception) {
            Log::error('Support request could not be created.', [
                'user_id' => Auth::id(),
                       'message' => $exception->getMessage(),
            ]);

            return back()
            ->with('error', 'Support request could not be created. Please try again later.')
            ->withInput();
        }
    }

    /**
     * Display the specified support request.
     */
    public function show(SupportRequest $supportRequest): View|RedirectResponse
    {
        try {
            $this->authorize('view', $supportRequest);

            if (! $supportRequest->isMainRequest()) {
                return redirect()
                ->route('support.index')
                ->with('error', 'Invalid support request.');
            }

            $messages = $supportRequest->messages()->with('user')->get();
            $captchaCode = $this->captchaService->generateCode();
            $captchaImage = $this->captchaService->generateImage($captchaCode);

            session(['captcha_code' => $captchaCode]);

            return view('support.show', compact('supportRequest', 'messages', 'captchaCode', 'captchaImage'));
        } catch (AuthorizationException $exception) {
            return redirect()
            ->route('support.index')
            ->with('error', 'You do not have permission to view this support request.');
        } catch (Exception $exception) {
            Log::error('Support request could not be loaded.', [
                'user_id' => Auth::id(),
                       'ticket_id' => $supportRequest->ticket_id ?? null,
                       'message' => $exception->getMessage(),
            ]);

            return redirect()
            ->route('support.index')
            ->with('error', 'Support request could not be loaded. Please try again later.');
        }
    }

    /**
     * Reply to the specified support request.
     */
    public function reply(Request $request, SupportRequest $supportRequest): RedirectResponse
    {
        try {
            $this->authorize('reply', $supportRequest);

            if (! $supportRequest->isMainRequest()) {
                return redirect()
                ->route('support.index')
                ->with('error', 'Invalid support request.');
            }

            if ($supportRequest->status === 'closed') {
                return redirect()
                ->route('support.show', $supportRequest->ticket_id)
                ->with('error', 'Cannot reply to a closed support request. If you need further assistance, please create a new support request.');
            }

            $validator = Validator::make($request->all(), [
                'message' => 'required|string|min:8|max:4000',
                'captcha' => 'required|string|min:2|max:8',
            ]);

            if ($validator->fails()) {
                return back()
                ->with('error', $validator->errors()->first())
                ->withInput();
            }

            $captchaCode = session('captcha_code');

            if (! $captchaCode || ! hash_equals(strtoupper($captchaCode), strtoupper($request->captcha))) {
                return back()
                ->with('error', 'Invalid CAPTCHA code.')
                ->withInput();
            }

            session()->forget('captcha_code');

            $supportRequest->messages()->create([
                'user_id' => Auth::id(),
                                                'message' => $request->message,
                                                'is_admin_reply' => false,
            ]);

            return redirect()
            ->route('support.show', $supportRequest->ticket_id)
            ->with('success', 'Reply sent successfully.');
        } catch (AuthorizationException $exception) {
            return redirect()
            ->route('support.index')
            ->with('error', 'You do not have permission to reply to this support request.');
        } catch (Exception $exception) {
            Log::error('Reply could not be sent.', [
                'user_id' => Auth::id(),
                       'ticket_id' => $supportRequest->ticket_id ?? null,
                       'message' => $exception->getMessage(),
            ]);

            return back()
            ->with('error', 'Reply could not be sent. Please try again later.')
            ->withInput();
        }
    }
}
