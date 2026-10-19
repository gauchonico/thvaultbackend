<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PreviewUsage extends Model
{
    protected $fillable = ['user_id', 'period', 'seconds_used', 'last_beat_at'];

    protected $casts = ['last_beat_at' => 'datetime'];

    /** The current allowance period, in Uganda time. */
    public static function currentPeriod(): string
    {
        $now = now('Africa/Kampala');

        return match (Setting::previewReset()) {
            'day'   => $now->format('Y-m-d'),
            'never' => 'all',
            default => $now->format('Y-m'),
        };
    }

    public static function secondsLeft(User $user): int
    {
        $used = (int) static::where('user_id', $user->id)->where('period', static::currentPeriod())->value('seconds_used');

        return max(0, Setting::previewSeconds() - $used);
    }
}
