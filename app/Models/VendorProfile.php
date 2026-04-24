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

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'description',
        'vendor_policy',
        'vacation_mode',
        'private_shop_mode',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'vacation_mode' => 'boolean',
        'private_shop_mode' => 'boolean',
    ];

    /**
     * Get the user that owns the vendor profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
