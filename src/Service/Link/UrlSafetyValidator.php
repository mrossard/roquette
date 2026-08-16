<?php

declare(strict_types=1);

namespace App\Service\Link;

/**
 * Validates URLs and IP addresses to protect against SSRF (Server-Side Request Forgery).
 */
final class UrlSafetyValidator
{
    /**
     * IPv4 ranges not covered by FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
     * but that must never be fetched (SSRF protection).
     */
    private const array EXTRA_BLOCKED_RANGES = [
        ['100.64.0.0', '100.127.255.255'], // CGNAT
        ['198.18.0.0', '198.19.255.255'], // Benchmarking
        ['192.0.0.0',  '192.0.0.255'], // IETF protocol assignments
        ['0.0.0.0',    '0.255.255.255'], // "This network"
    ];

    /**
     * Vérifie si l'URL est valide et sûre (évite SSRF).
     */
    public function isSafeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || ($parsed['scheme'] ?? null) === null || ($parsed['host'] ?? null) === null) {
            return false;
        }

        $scheme = strtolower((string) $parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = (string) $parsed['host'];
        $cleanHost = $host;
        if (str_starts_with($cleanHost, '[') && str_ends_with($cleanHost, ']')) {
            $cleanHost = substr($cleanHost, 1, -1);
        }

        $ips = $this->resolveHostIps($cleanHost);
        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!$this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Résout une URL de redirection, éventuellement relative, par rapport à l'URL courante.
     */
    public function resolveUrl(string $base, string $location): ?string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? null;
        if ($host === null) {
            return null;
        }

        $port = array_key_exists('port', $parsed) && $parsed['port'] !== null ? ':' . $parsed['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = $parsed['path'] ?? '/';
        $dir = str_replace('\\', '/', dirname($path));
        if ($dir === '.' || $dir === '/') {
            $dir = '';
        }

        return $scheme . '://' . $host . $port . $dir . '/' . $location;
    }

    /**
     * @return list<string>
     */
    private function resolveHostIps(string $cleanHost): array
    {
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            return [$cleanHost];
        }

        $ips = [];

        // Résolution DNS des enregistrements IPv4 (A)
        $ipv4Records = dns_get_record($cleanHost, DNS_A);
        if (is_array($ipv4Records)) {
            foreach ($ipv4Records as $record) {
                if (($record['ip'] ?? null) === null) {
                    continue;
                }

                $ips[] = (string) $record['ip'];
            }
        }

        // Résolution DNS des enregistrements IPv6 (AAAA)
        $ipv6Records = dns_get_record($cleanHost, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (($record['ipv6'] ?? null) === null) {
                    continue;
                }

                $ips[] = (string) $record['ipv6'];
            }
        }

        // Repli vers gethostbynamel si aucune IP n'a été résolue (ex. fichiers hosts locaux)
        if ($ips === []) {
            $fallbackIps = gethostbynamel($cleanHost);
            if (is_array($fallbackIps)) {
                $ips = array_merge($ips, $fallbackIps);
            }
        }

        return $ips;
    }

    /**
     * Retourne true si l'IP est publique (ni privée, ni réservée, ni dans les
     * plages bloquées supplémentaires).
     */
    public function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (ex. ::ffff:127.0.0.1) — vérifie la partie IPv4.
        $lower = strtolower($ip);
        if (str_starts_with($lower, '::ffff:')) {
            $v4 = substr($lower, 7);
            if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return (
                    filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
                    && !$this->isInExtraBlockedRange($v4)
                );
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return !$this->isInExtraBlockedRange($ip);
    }

    private function isInExtraBlockedRange(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_RANGES as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }
}
