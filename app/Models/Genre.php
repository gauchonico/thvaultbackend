<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Genre extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    /**
     * Boot function to automatically generate a slug from the name.
     */
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($genre) {
            $genre->slug = $genre->slug ?? Str::slug($genre->name);
        });
    }

    /**
     * Relationship back to Shows.
     */
    public function shows(): BelongsToMany
    {
        return $this->belongsToMany(Show::class);
    }
}