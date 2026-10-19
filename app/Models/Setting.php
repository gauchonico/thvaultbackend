<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** Admin-editable settings. Falls back to config/env values when never saved. */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];
    protected $casts = ['value' => 'json'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Cache::rememberForever("setting:{$key}", fn () => static::find($key)?->value);

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("setting:{$key}");
    }

    // Freemium preview allowance (seconds) and when it refills (month | day | never).
    public static function previewSeconds(): int
    {
        return (int) static::get('preview_seconds', config('content.preview_seconds'));
    }

    public static function previewReset(): string
    {
        return (string) static::get('preview_reset', config('content.preview_reset'));
    }
}
