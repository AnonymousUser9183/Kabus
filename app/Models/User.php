<?php

/*
 | *--------------------------------------------------------------------------
 | Copyright Notice
 |--------------------------------------------------------------------------
 | Updated for Laravel 13.4.0 by AnonymousUser9183 / The Erebus Development Team.
 | Original Kabus Marketplace Script created by Sukunetsiz.
 |--------------------------------------------------------------------------
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, HasUuids, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'password',
        'last_login',
        'mnemonic',
        'password_reset_token',
        'password_reset_expires_at',
        'reference_id',
        'referred_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mnemonic',
        'password_reset_token',
        'reference_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login' => 'datetime',
            'mnemonic' => 'encrypted',
            'password_reset_expires_at' => 'datetime',
            'reference_id' => 'encrypted',
        ];
    }

    /**
     * Get all conversations for the user.
     */
    public function conversations()
    {
        return Message::conversation()
        ->where(function ($query) {
            $query->where('user_id_1', $this->id)
            ->orWhere('user_id_2', $this->id);
        });
    }

    /**
     * Get the messages sent by the user.
     */
    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id')->regularMessage();
    }

    /**
     * Check if the user has reached the conversation limit.
     */
    public function hasReachedConversationLimit(): bool
    {
        return $this->conversations()->count() >= 16;
    }

    /**
     * Get the user's profile.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get the user's vendor profile.
     */
    public function vendorProfile(): HasOne
    {
        return $this->hasOne(VendorProfile::class);
    }

    /**
     * Get the PGP key associated with the user.
     */
    public function pgpKey(): HasOne
    {
        return $this->hasOne(PgpKey::class);
    }

    /**
     * Get the roles for the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Check if the user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Check if the user is a vendor.
     */
    public function isVendor(): bool
    {
        return $this->hasRole('vendor');
    }

    /**
     * Get the ban information for the user.
     */
    public function bannedUser(): HasOne
    {
        return $this->hasOne(BannedUser::class);
    }

    /**
     * Check if the user is currently banned.
     */
    public function isBanned(): bool
    {
        return $this->bannedUser && $this->bannedUser->banned_until > now();
    }

    /**
     * Get the return addresses for the user.
     */
    public function returnAddresses(): HasMany
    {
        return $this->hasMany(ReturnAddress::class);
    }

    /**
     * The attributes that should be cast on the pivot.
     *
     * @var array<string, string>
     */
    protected $pivotCasts = [
        'read' => 'boolean',
    ];

    /**
     * Get the notifications for the user.
     */
    public function notifications(): BelongsToMany
    {
        return $this->belongsToMany(Notification::class)
        ->withTimestamps()
        ->withPivot('read')
        ->orderBy('notification_user.created_at', 'desc');
    }

    /**
     * Get the wishlisted products for the user.
     */
    public function wishlist(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')
        ->withTimestamps()
        ->orderBy('wishlists.created_at', 'desc');
    }

    /**
     * Check if a product is in the user's wishlist.
     */
    public function hasWishlisted(string $productId): bool
    {
        return $this->wishlist()->where('products.id', $productId)->exists();
    }

    /**
     * Get the user's cart items.
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    /**
     * Get the user who referred this user.
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Get users who were referred by this user.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * Get the count of unread notifications for the user.
     */
    public function getUnreadNotificationsCountAttribute(): int
    {
        return $this->notifications()
        ->wherePivot('read', false)
        ->count();
    }

    /**
     * Get the secret phrase associated with the user.
     */
    public function secretPhrase(): HasOne
    {
        return $this->hasOne(SecretPhrase::class);
    }
}
