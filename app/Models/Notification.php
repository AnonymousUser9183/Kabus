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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

class Notification extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'message',
        'target_role',
        'type',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'type' => 'string',
    ];

    /**
     * Validation rules for the model.
     *
     * @var array<string, string>
     */
    private static array $rules = [
        'title' => 'required|string|min:3|max:255',
        'message' => 'required|string|min:10|max:5000',
        'target_role' => 'nullable|string|in:admin,vendor',
        'type' => 'required|string|in:bulk,message,support',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $validator = Validator::make($model->toArray(), self::$rules);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $model->title = strip_tags($model->title);
            $model->message = strip_tags($model->message, '<p><br><strong><em><ul><li><ol>');
        });
    }

    /**
     * The users that belong to the notification.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
        ->withTimestamps()
        ->withPivot('read');
    }

    /**
     * Get all users that should receive this notification based on target_role.
     */
    public function getTargetUsers(): LazyCollection
    {
        try {
            $query = User::query();

            if ($this->target_role !== null) {
                $query->whereHas('roles', function ($query): void {
                    $query->where('name', $this->target_role);
                });
            }

            return $query->select('id')->cursor();
        } catch (\Exception $e) {
            Log::error('Error getting target users for notification: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Send the notification to target users.
     */
    public function sendToTargetUsers(): void
    {
        try {
            $userIds = [];

            foreach ($this->getTargetUsers() as $user) {
                $userIds[] = $user->id;

                if (count($userIds) === 1000) {
                    $this->users()->attach($userIds);
                    $userIds = [];
                }
            }

            if (! empty($userIds)) {
                $this->users()->attach($userIds);
            }

            Log::info("Notification {$this->id} sent successfully to users");
        } catch (\Exception $e) {
            Log::error('Error sending notification to users: '.$e->getMessage());
            throw $e;
        }
    }
}
