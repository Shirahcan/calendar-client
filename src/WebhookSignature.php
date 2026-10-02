<?php

namespace Shirahcan\CalendarClient;

/**
 * Verifies a callback from calendar-service: headers `X-Calendar-Signature` (base64) and
 * `X-Calendar-Timestamp`, base64 HMAC-SHA256 over `"{timestamp}.{rawBody}"` with the
 * base64-decoded callback secret. The same scheme video-service uses.
 *
 * Handlers MUST also be idempotent on the body's `event_id`: a retried delivery is the
 * same event, not a second one.
 */
final class WebhookSignature
{
    /** A callback older than this is refused (replay). Matches the service's longest backoff. */
    public const TOLERANCE_SECONDS = 86400;

    public static function verify(string $rawBody, ?string $signature, ?string $timestamp, string $base64Secret, ?int $now = null): bool
    {
        if ($signature === null || $signature === '' || $timestamp === null || ! ctype_digit($timestamp) || $base64Secret === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $secret = base64_decode($base64Secret, true);
        if ($secret === false || $secret === '') {
            return false;
        }

        return hash_equals(base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret, true)), $signature);
    }
}
