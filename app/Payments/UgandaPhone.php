<?php

namespace App\Payments;

/** Normalises Ugandan mobile numbers to 2567XXXXXXXX and guesses the network. */
class UgandaPhone
{
    // Prefix (after 256) → network. Ported numbers can be wrong, so this is only a
    // hint for the UI; the provider decides where the prompt actually goes.
    private const NETWORKS = [
        '76' => 'mtn', '77' => 'mtn', '78' => 'mtn',
        '70' => 'airtel', '74' => 'airtel', '75' => 'airtel',
    ];

    /** Returns null when the input isn't a Ugandan mobile number. */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input);

        if (str_starts_with($digits, '0')) {
            $digits = '256' . substr($digits, 1);
        } elseif (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '256' . $digits;
        }

        return preg_match('/^2567\d{8}$/', $digits) ? $digits : null;
    }

    public static function network(string $normalized): ?string
    {
        return self::NETWORKS[substr($normalized, 3, 2)] ?? null;
    }
}
