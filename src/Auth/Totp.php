<?php

declare(strict_types=1);

namespace Edict\Auth;

/**
 * RFC 6238 time-based one-time passwords (SHA-1, 30 s, 6 digits), the variant
 * every authenticator app supports. Secrets are raw bytes here; base32 is only
 * for showing them to the user.
 */
final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return random_bytes(20);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Finds the time step a code belongs to, allowing one step of clock drift
     * either way. The caller must reject steps that were already used.
     */
    public static function matchStep(string $secret, string $code, int $now): ?int
    {
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }

        $current = intdiv($now, self::PERIOD);
        $matched = null;
        foreach ([$current - 1, $current, $current + 1] as $step) {
            // No early exit: every candidate is always compared.
            if (hash_equals(self::code($secret, $step), $code)) {
                $matched = $step;
            }
        }

        return $matched;
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function provisioningUri(string $secret, string $account): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode('Edict'),
            rawurlencode($account),
            self::base32($secret),
            rawurlencode('Edict'),
            self::DIGITS,
            self::PERIOD,
        );
    }
}
