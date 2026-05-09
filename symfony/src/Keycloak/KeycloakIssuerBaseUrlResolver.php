<?php

declare(strict_types=1);

namespace App\Keycloak;

use LogicException;

final class KeycloakIssuerBaseUrlResolver
{
    public function resolve(string $issuer): string
    {
        $parts = parse_url($issuer);
        if (!is_array($parts)) {
            throw new LogicException(sprintf('Unable to parse issuer URL "%s".', $issuer));
        }

        $scheme = (string) ($parts['scheme'] ?? '');
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === '' || $host === '') {
            throw new LogicException(sprintf('Issuer URL "%s" does not contain scheme and host.', $issuer));
        }

        $port = $parts['port'] ?? null;
        $path = (string) ($parts['path'] ?? '');
        $realmPosition = strpos($path, '/realms/');
        if ($realmPosition !== false) {
            $path = substr($path, 0, $realmPosition);
        } else {
            $path = '';
        }

        $baseUrl = $scheme . '://' . $host;
        if (is_int($port)) {
            $baseUrl .= ':' . $port;
        }

        $normalizedPath = trim($path, '/');
        if ($normalizedPath !== '') {
            $baseUrl .= '/' . $normalizedPath;
        }

        return $baseUrl;
    }
}
