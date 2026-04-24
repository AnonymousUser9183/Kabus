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

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'parent_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
        ];
    }

    /**
     * Get the parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Get the subcategories for the category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get the products for the category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Check if the category is a main category.
     */
    public function isMainCategory(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Get all main categories.
     *
     * @return Collection<int, self>
     */
    public static function mainCategories(): Collection
    {
        return static::query()
        ->whereNull('parent_id')
        ->with('children')
        ->orderBy('name')
        ->get();
    }

    /**
     * Get formatted name for display.
     */
    public function getFormattedName(): string
    {
        if ($this->isMainCategory() || ! $this->parent) {
            return $this->name;
        }

        return $this->parent->name.' > '.$this->name;
    }

    /**
     * Get validation rules for category.
     *
     * @return array<string, string>
     */
    public static function validationRules(): array
    {
        return [
            'name' => 'required|string|min:1|max:16',
            'parent_id' => 'nullable|exists:categories,id',
        ];
    }
}
