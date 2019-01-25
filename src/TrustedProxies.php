<?php declare(strict_types=1);

namespace Kuria\RequestInfo;

use Kuria\RequestInfo\Exception\HeaderConflictException;
use Kuria\RequestInfo\Helper\IpMatcher;
use Kuria\RequestInfo\Helper\Parser;

/**
 * Define trusted proxies and headers
 */
class TrustedProxies
{
    /** "Forwarded" header (RFC 7239)  */
    const HEADER_FORWARDED = 1;

    /** "X-Forwarded-For" header */
    const HEADER_X_FORWARDED_FOR = 2;

    /** "X-Forwarded-Host" header */
    const HEADER_X_FORWARDED_HOST = 4;

    /** "X-Forwarded-Proto" header */
    const HEADER_X_FORWARDED_PROTO = 8;

    /** "X-Forwarded-Port" header */
    const HEADER_X_FORWARDED_PORT = 16;

    /** "X-Forwarded-*" headers */
    const HEADER_X_FORWARDED_ALL = 30;

    /** Map of "Forwarded" header props to "X-Forwarded-*" header types */
    private const FORWARDED_PROP_HEADER_X = [
        'for' => self::HEADER_X_FORWARDED_FOR,
        'host' => self::HEADER_X_FORWARDED_HOST,
        'port' => self::HEADER_X_FORWARDED_PORT,
        'proto' => self::HEADER_X_FORWARDED_PROTO,
    ];

    /** @var string[] */
    private $trustedProxies;

    /** @var int */
    private $trustedProxyHeaders;

    /**
     * @param string[] $trustedProxies list of trusted proxy IP adresses or subnets in CIDR notation
     * @param int $trustedProxyHeaders bit field of TrustedProxies::HEADER_* constants to define which headers to trust
     */
    function __construct(array $trustedProxies, int $trustedProxyHeaders = 0)
    {
        $this->trustedProxies = $trustedProxies;
        $this->trustedProxyHeaders = $trustedProxyHeaders;
    }

    /**
     * @return string[]
     */
    function getTrustedProxies(): array
    {
        return $this->trustedProxies;
    }

    function getTrustedProxyHeaders(): int
    {
        return $this->trustedProxyHeaders;
    }

    /**
     * See if an IP address is contained in the list of trusted proxies
     */
    function isIpFromTrustedProxy(string $ip): bool
    {
        return $this->trustedProxies && IpMatcher::match($ip, $this->trustedProxies);
    }

    /**
     * See if a header is one of the trusted proxy headers
     *
     * @param int $headerType see TrustedProxies::HEADER_* constants
     */
    function isTrustedProxyHeader(int $headerType): bool
    {
        return ($this->trustedProxyHeaders & $headerType) !== 0;
    }

    /**
     * Extract information from trusted "Forwarded" or "X-Forwarded-*" headers
     *
     * @param string[] $headers header map (with lowercase keys)
     * @param array[]|null $forwardedHeaderGroups property groups from the "Forwarded" header
     * @param string $prop for, host, port or proto
     * @param callable $filter optional callback to filter and/or normalize the values
     * @throws \OutOfBoundsException if $prop is not valid
     * @throws HeaderConflictException if trusted headers report conflicting values
     * @return string[]
     */
    function getForwardedPropValues(array $headers, ?array $forwardedHeaderGroups, string $prop, ?callable $filter = null): array
    {
        if (!isset(self::FORWARDED_PROP_HEADER_X[$prop])) {
            throw new \OutOfBoundsException(sprintf(
                'Invalid prop "%s", valid props are: %s',
                $prop,
                implode(', ', array_keys(self::FORWARDED_PROP_HEADER_X))
            ));
        }

        $xHeaderType = self::FORWARDED_PROP_HEADER_X[$prop];
        $xHeaderName = "x-forwarded-{$prop}";

        $forwardedValues = [];
        $xHeaderValues = [];

        // get prop values from the "Forwarded" header
        if ($this->isTrustedProxyHeader(static::HEADER_FORWARDED) && $forwardedHeaderGroups) {
            foreach ($forwardedHeaderGroups as $forwardedHeaderGroup) {
                switch ($prop) {
                    case 'port':
                        $forwardedValues[] = $this->getForwardedHeaderGroupPort($forwardedHeaderGroup);
                        break;

                    case 'host':
                        $forwardedValues[] = Parser::parseHostAndPort((string) $forwardedHeaderGroup['host'])['host'];
                        break;

                    default:
                        $forwardedValues[] = (string) $forwardedHeaderGroup[$prop];
                        break;
                }
            }
        }

        // get values from an "X-Forwarded-*" header
        if ($this->isTrustedProxyHeader($xHeaderType) && isset($headers[$xHeaderName])) {
            foreach (explode(',', $headers[$xHeaderName]) as $value) {
                $value = trim($value);

                if ($prop === 'port') {
                    if (ctype_digit($value)) {
                        $xHeaderValues[] = (int) $value;
                    }
                } else {
                    $xHeaderValues[] = $value;
                }
            }
        }

        // apply the filter
        if ($filter !== null) {
            $forwardedValues = $filter($forwardedValues);
            $xHeaderValues = $filter($xHeaderValues);
        }

        // choose which values to return
        if ($forwardedValues && $xHeaderValues && $forwardedValues !== $xHeaderValues) {
            throw new HeaderConflictException(sprintf(
                'The request contains both trusted header "forwarded" and trusted header "%s",'
                . ' but they report different values for the property "%s".'
                . "\n\nforwarded: %s\n%1\$s: %s\n\n"
                . 'You can distrust one of them or configure the proxy to send correct headers.',
                $xHeaderName,
                $prop,
                implode(', ', $forwardedValues),
                implode(', ', $xHeaderValues)
            ));
        }

        return $forwardedValues ?:$xHeaderValues;
    }

    private function getForwardedHeaderGroupPort(array $group): int
    {
        $port = null;

        if ($group['host'] !== null) {
            $port = Parser::parseHostAndPort($group['host'])['port'];
            /** @var int|null $port */
        }

        return $port ?? ($group['proto'] === 'https' ? 443 : 80);
    }
}
