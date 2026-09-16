<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeroSlide extends Model
{
    protected $fillable = ['show_id', 'order', 'active'];
    protected $casts    = ['active' => 'boolean'];
    public function show(): BelongsTo { return $this->belongsTo(Show::class); }
}