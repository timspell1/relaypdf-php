<?php

declare(strict_types=1);

namespace RelayPDF;

final class Webhooks
{
    public const SIGNATURE_HEADER = 'RelayPDF-Signature';
    public const EVENT_HEADER = 'RelayPDF-Event';

    public static function verify(string $secret, string $body, string $header, int $toleranceSec = 300): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $item) {
            if (!str_contains($item, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $item, 2);
            $parts[trim($key)] = trim($value);
        }
        if (!isset($parts['t'], $parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $timestamp = (int) $parts['t'];
        if (abs(time() - $timestamp) > $toleranceSec) {
            return false;
        }
        $digest = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return hash_equals($digest, $parts['v1']);
    }

    public static function toBase64(string $data): string
    {
        if (str_starts_with($data, 'data:') && str_contains($data, ';base64,')) {
            return explode(';base64,', $data, 2)[1];
        }
        return base64_encode($data);
    }
}

function verify_webhook(string $secret, string $body, string $header, int $tolerance_sec = 300): bool
{
    return Webhooks::verify($secret, $body, $header, $tolerance_sec);
}
