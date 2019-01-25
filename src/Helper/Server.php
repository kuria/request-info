<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

use Kuria\RequestInfo\Exception\UndefinedServerValueException;

/**
 * Access $_SERVER values
 */
abstract class Server
{
    private const CONTENT_HEADER_MAP = [
        'content-length' => true,
        'content-type' => true,
        'content-md5' => true,
    ];

    /**
     * Get a server value
     *
     * Returns NULL if no such value is defined.
     */
    static function get(string $key): ?string
    {
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /**
     * Require a server value
     *
     * @throws UndefinedServerValueException if no such value is defined
     */
    static function require(string $key): string
    {
        if (!isset($_SERVER[$key])) {
            throw new UndefinedServerValueException(sprintf('$_SERVER[%s] is not defined', $key));
        }

        return (string) $_SERVER[$key];
    }

    /**
     * See if a server value is defined
     */
    static function has(string $key): bool
    {
        return isset($_SERVER[$key]);
    }

    /**
     * See if a server value is defined and is not empty
     */
    static function hasNotEmpty(string $key): bool
    {
        return isset($_SERVER[$key]) && $_SERVER[$key] !== '';
    }

    /**
     * Extract headers from server variables
     *
     * All headers names will be lowercase.
     */
    static function getHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                // HTTP_*
                $headers[self::normalizeHeaderName(substr($key, 5))] = $value;
            } elseif (strncmp($key, 'CONTENT_', 8) === 0) {
                // CONTENT_*
                $contentHeader = self::normalizeHeaderName($key);

                if (isset(self::CONTENT_HEADER_MAP[$contentHeader])) {
                    $headers[$contentHeader] = $value;
                }
            }
        }

        return $headers;
    }

    private static function normalizeHeaderName(string $name): string
    {
        return str_replace('_', '-', strtolower($name));
    }
}
