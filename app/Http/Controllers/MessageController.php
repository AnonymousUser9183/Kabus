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

use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Exception;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (RateLimiter::tooManyAttempts('send-message:'.Auth::id(), $perMinute = 5)) {
                Log::warning('Rate limit exceeded for user: '.Auth::id());

                return response()->view('messages.rate-limit', [], 429);
            }

            RateLimiter::hit('send-message:'.Auth::id());

            return $next($request);
        })->only(['store', 'startConversation']);
    }

    /**
     * Display the user's conversations.
     */
    public function index(): View
    {
        $user = Auth::user();

        $conversations = $user->conversations()
        ->with(['user1', 'user2'])
        ->orderBy('last_message_at', 'desc')
        ->paginate(4);

        return view('messages.index', compact('conversations'));
    }

    /**
     * Display the specified conversation.
     */
    public function show(int $id): View|Response
    {
        $conversation = Message::conversation()->findOrFail($id);

        if (RateLimiter::tooManyAttempts('view-conversation:'.Auth::id(), $perMinute = 60)) {
            return response()->view('messages.rate-limit', [], 429);
        }

        RateLimiter::hit('view-conversation:'.Auth::id());

        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
        ->with('sender')
        ->orderBy('created_at', 'desc')
        ->paginate(40);

        $unreadMessages = $messages->getCollection()
        ->where('is_read', false)
        ->where('sender_id', '!=', Auth::id());

        foreach ($unreadMessages as $message) {
            $message->is_read = true;
            $message->save();
        }

        return view('messages.show', compact('conversation', 'messages'));
    }

    /**
     * Store a newly created message in an existing conversation.
     */
    public function store(Request $request, int $id): RedirectResponse
    {
        $conversation = Message::conversation()->findOrFail($id);
        $this->authorize('sendMessage', $conversation);

        if ($conversation->hasReachedMessageLimit()) {
            return redirect()
            ->back()
            ->with('error', 'Message limit of 40 has been reached for this chat. Please delete this chat and start a new one with the user.');
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|min:4|max:1600',
        ]);

        if ($validator->fails()) {
            return redirect()
            ->back()
            ->with('error', $validator->errors()->first())
            ->withInput();
        }

        $validatedData = $validator->validated();
        $cleanContent = $this->purifyMessageContent($validatedData['content']);

        $message = new Message([
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
                               'content' => $cleanContent,
        ]);

        if (! $message->save()) {
            Log::error('Failed to save message', [
                'user_id' => Auth::id(),
                       'conversation_id' => $conversation->id,
            ]);

            return redirect()
            ->back()
            ->with('error', 'Failed to send message. Please try again.');
        }

        $conversation->last_message_at = now();
        $conversation->save();

        $recipientId = $conversation->user_id_1 == Auth::id()
        ? $conversation->user_id_2
        : $conversation->user_id_1;

        $recipient = User::find($recipientId);

        if ($recipient) {
            $existingNotification = $recipient->notifications()
            ->where('title', 'LIKE', 'New message from '.Auth::user()->username)
            ->wherePivot('read', false)
            ->first();

            if (! $existingNotification) {
                $notification = new Notification([
                    'title' => 'New message from '.Auth::user()->username,
                                                 'message' => 'You have received a new message from '.Auth::user()->username,
                                                 'type' => 'message',
                ]);

                $notification->save();
                $notification->users()->attach($recipientId, ['read' => false]);
            }
        }

        return redirect()->route('messages.show', $conversation);
    }

    /**
     * Show the form for creating a new conversation.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $username = $request->query('username');

        if (Auth::user()->hasReachedConversationLimit()) {
            return redirect()
            ->route('messages.index')
            ->with('error', 'Conversation limit of 16 reached. Please delete other conversations to create a new one.');
        }

        return view('messages.create', ['username' => $username]);
    }

    /**
     * Start a new conversation or continue an existing one.
     */
    public function startConversation(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|alpha_num|max:16',
            'content' => 'required|string|min:4|max:1600',
        ]);

        if ($validator->fails()) {
            return redirect()
            ->back()
            ->with('error', $validator->errors()->first())
            ->withInput();
        }

        $validatedData = $validator->validated();

        if ($validatedData['username'] === Auth::user()->username) {
            return redirect()
            ->back()
            ->with('error', 'You cannot send messages to yourself.')
            ->withInput();
        }

        $otherUser = User::where('username', $validatedData['username'])->first();

        if (! $otherUser) {
            return redirect()
            ->back()
            ->with('error', 'The specified user does not exist.')
            ->withInput();
        }

        if ($otherUser->id === Auth::id()) {
            return redirect()
            ->back()
            ->with('error', 'You cannot start a chat with yourself.')
            ->withInput();
        }

        $existingConversation = Message::findConversation(Auth::id(), $otherUser->id);

        if ($existingConversation) {
            $conversation = $existingConversation;

            if ($conversation->hasReachedMessageLimit()) {
                return redirect()
                ->back()
                ->with('error', 'Message limit of 40 has been reached for the existing chat. Please delete it and start a new one.');
            }
        } else {
            if (Auth::user()->hasReachedConversationLimit()) {
                return redirect()
                ->back()
                ->with('error', 'Chat limit of 16 has been reached. Please delete other chats to create a new one.');
            }

            $conversation = Message::createConversation(Auth::id(), $otherUser->id);
        }

        $cleanContent = $this->purifyMessageContent($validatedData['content']);

        $message = new Message([
            'conversation_id' => $conversation->id,
            'sender_id' => Auth::id(),
                               'content' => $cleanContent,
        ]);

        if (! $message->save()) {
            Log::error('Failed to save initial message', [
                'user_id' => Auth::id(),
                       'with_user_id' => $otherUser->id,
            ]);

            return redirect()
            ->back()
            ->with('error', 'Failed to start chat. Please try again.');
        }

        $conversation->last_message_at = now();
        $conversation->save();

        $notification = new Notification([
            'title' => 'New message from '.Auth::user()->username,
                                         'message' => 'You have received a new message from '.Auth::user()->username,
                                         'type' => 'message',
        ]);

        $notification->save();
        $notification->users()->attach($otherUser->id, ['read' => false]);

        return redirect()->route('messages.show', $conversation);
    }

    /**
     * Remove the specified conversation from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $conversation = Message::conversation()->findOrFail($id);
        $this->authorize('delete', $conversation);

        try {
            $conversation->delete();

            return redirect()
            ->route('messages.index')
            ->with('success', 'Chat successfully deleted.');
        } catch (Exception $exception) {
            Log::error('Failed to delete conversation', [
                'user_id' => Auth::id(),
                       'conversation_id' => $conversation->id,
                       'error' => $exception->getMessage(),
            ]);

            return redirect()
            ->back()
            ->with('error', 'Failed to delete chat. Please try again.');
        }
    }

    /**
     * Purify message content.
     */
    private function purifyMessageContent(string $content): string
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,b,i,u,a[href],ul,ol,li');

        $purifier = new HTMLPurifier($config);

        return $purifier->purify($content);
    }
}
