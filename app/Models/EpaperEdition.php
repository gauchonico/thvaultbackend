<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EpaperEdition extends Model
{
    protected $fillable = [
        'publication_id', 'issue_date', 'title', 'cover', 'file_path', 'file_size',
        'page_count', 'access_level', 'is_published',
    ];

    protected $casts = [
        'issue_date'   => 'date:Y-m-d',
        'file_size'    => 'integer',
        'page_count'   => 'integer',
        'is_published' => 'boolean',
    ];

    // The PDF is only ever streamed through the API (after an access check), never linked.
    protected $hidden = ['file_path'];

    /** Disk for edition PDFs. Point EPAPER_DISK at a private bucket to keep them off the public one. */
    public static function disk(): string
    {
        return config('filesystems.epaper_disk', 'public');
    }

    public function getCoverAttribute(?string $value): ?string
    {
        if (!$value) return null;
        return Str::startsWith($value, ['http://', 'https://']) ? $value : Storage::disk('public')->url($value);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    /**
     * Same rules as shows: Free = any signed-in account, Subscribers = a paid plan.
     * Admins can always read (they preview uploads from the console).
     *
     * @return array{has_access: bool, reason: ?string}
     */
    public function accessFor(?User $user): array
    {
        if (!$user) {
            return ['has_access' => false, 'reason' => 'login'];
        }
        if ($user->is_admin || $this->access_level === 'free' || $user->isPaidSubscriber()) {
            return ['has_access' => true, 'reason' => null];
        }

        return ['has_access' => false, 'reason' => 'subscribe'];
    }
}
