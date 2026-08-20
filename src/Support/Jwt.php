<?php

namespace Nosh\OmniConnect\Support;

/**
 * Minimal, dependency-free HS256/HS512 JWT encode + verify.
 *
 * Used to (a) verify the JWT Delivery Hero puts in the Authorization header of
 * every inbound Plugin-API call — signed with the shared secret and carrying a
 * `service: middleware` claim — and (b) let the local simulator sign a matching
 * token so the inbound loop is testable with no real platform.
 */
final class Jwt
{
    public static function encode(array $payload, string $secret, string $alg = 'HS512'): string
    {
        $header = ['typ' => 'JWT', 'alg' => $alg];
        $segments = [
            self::b64(json_encode($header)),
            self::b64(json_encode($payload)),
        ];
        $signing = implode('.', $segments);
        $segments[] = self::b64(self::sign($signing, $secret, $alg));

        return implode('.', $segments);
    }

    /**
     * Verify signature + expiry. Returns the decoded payload, or null if the
     * token is malformed / the signature doesn't match / it has expired.
     */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $header = json_decode(self::b64d($h), true);
        $payload = json_decode(self::b64d($p), true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        $alg = $header['alg'] ?? 'HS512';
        $expected = self::b64(self::sign($h.'.'.$p, $secret, $alg));
        if (! hash_equals($expected, $s)) {
            return null;
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /** Read a token's payload without verifying (for logging/inspection only). */
    public static function payload(string $token): ?array
    {
        $parts = explode('.', $token);

        return count($parts) === 3 ? (json_decode(self::b64d($parts[1]), true) ?: null) : null;
    }

    private static function sign(string $data, string $secret, string $alg): string
    {
        $map = ['HS256' => 'sha256', 'HS512' => 'sha512'];
        $hash = $map[strtoupper($alg)] ?? 'sha512';

        return hash_hmac($hash, $data, $secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64d(string $enc): string
    {
        return base64_decode(strtr($enc, '-_', '+/')) ?: '';
    }
}
