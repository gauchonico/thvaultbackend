<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * DRM-protected video via VdoCipher. A video can't be played from its ID alone: for
 * every playback the server asks VdoCipher for a short-lived OTP, which the player
 * then uses. The API secret never leaves the server.
 */
class VdoCipher
{
    private const BASE = 'https://dev.vdocipher.com/api';

    // An OTP only has to be used to start playback; once started it plays to the end.
    private const OTP_TTL_SECONDS = 300;

    // Episode video links are stored as "vdocipher:<id>". A bare ID is accepted too.
    public static function videoId(?string $videoUrl): ?string
    {
        return $videoUrl && preg_match('/^(?:vdocipher:)?([0-9a-f]{32})$/i', trim($videoUrl), $m)
            ? strtolower($m[1])
            : null;
    }

    public static function configured(): bool
    {
        return (bool) config('services.vdocipher.secret');
    }

    /**
     * @param string|null $watermark Text that drifts across the picture (the viewer's
     *        email), so a screen recording can be traced back to the account.
     * @return array{otp: string, playbackInfo: string}
     */
    public function otp(string $videoId, ?string $watermark = null): array
    {
        $body = ['ttl' => self::OTP_TTL_SECONDS];
        if ($watermark) {
            $body['annotate'] = json_encode([[
                'type' => 'rtext', 'text' => $watermark,
                'alpha' => '0.60', 'color' => '0xFFFFFF', 'size' => '15', 'interval' => '5000',
            ]]);
        }

        $response = $this->client()->post(self::BASE . "/videos/{$videoId}/otp", $body);
        if ($response->failed() || !$response->json('otp')) {
            throw new RuntimeException("VdoCipher OTP failed ({$response->status()}): " . $response->body());
        }

        return ['otp' => $response->json('otp'), 'playbackInfo' => $response->json('playbackInfo')];
    }

    /** Video details for the admin auto-fill, or null when VdoCipher has no such video. */
    public function video(string $videoId): ?array
    {
        $response = $this->client()->get(self::BASE . "/videos/{$videoId}");
        if ($response->status() === 404) {
            return null;
        }
        if ($response->failed()) {
            throw new RuntimeException("VdoCipher lookup failed ({$response->status()}): " . $response->body());
        }
        return $response->json();
    }

    private function client(): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->asJson()
            ->withHeaders(['Authorization' => 'Apisecret ' . config('services.vdocipher.secret')]);
    }
}
