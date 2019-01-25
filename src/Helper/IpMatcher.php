<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

use Kuria\RequestInfo\Exception\UnsupportedOperationException;

/**
 * IP address matcher
 */
abstract class IpMatcher
{
    /**
     * Attempt to match IPv4 or IPv6 address against a list of allowed IP addressed or subnets
     */
    static function match(string $ip, array $list): bool
    {
        $method = strpos($ip, ':') === false ? 'matchIp4' : 'matchIp6';

        foreach ($list as $allowed) {
            if (static::$method($ip, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match IPv4 address against an allowed IP address or a subnet in CIDR notation
     */
    static function matchIp4(string $ip, string $allowed): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (($slashPos = strpos($allowed, '/')) !== false) {
            $address = substr($allowed, 0, $slashPos);
            $netmask = substr($allowed, $slashPos + 1);

            if ($netmask === '0') {
                return (bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            }

            $netmask = (int) $netmask;

            if ($netmask < 0 || $netmask > 32) {
                return false;
            }
        } else {
            $address = $allowed;
            $netmask = 32;
        }

        if (ip2long($address) === false) {
            return false;
        }

        return substr_compare(
            sprintf('%032b', ip2long($ip)),
            sprintf('%032b', ip2long($address)),
            0,
            $netmask
        ) === 0;
    }

    /**
     * Match IPv6 address against an allowed IP address or a subnet in CIDR notation
     *
     * @throws UnsupportedOperationException if PHP was compiled without
     */
    static function matchIp6($requestIp, $ip): bool
    {
        if (!((extension_loaded('sockets') && defined('AF_INET6')) || @inet_pton('::1'))) {
            throw new UnsupportedOperationException('Missing IPv6 support (no sockets extension or PHP was compiled with --disable-ipv6)');
        }

        if (($slashPos = strpos($ip, '/')) !== false) {
            $address = substr($ip, 0, $slashPos);
            $netmask = substr($ip, $slashPos + 1);

            if ($netmask === '0') {
                return (bool) unpack('n*', (string) @inet_pton($address));
            }

            if ($netmask < 1 || $netmask > 128) {
                return false;
            }
        } else {
            $address = $ip;
            $netmask = 128;
        }

        $bytesAddr = unpack('n*', (string) @inet_pton($address));
        $bytesTest = unpack('n*', (string) @inet_pton($requestIp));

        if (!$bytesAddr || !$bytesTest) {
            return false;
        }

        for ($i = 1, $ceil = ceil($netmask / 16); $i <= $ceil; ++$i) {
            $left = $netmask - 16 * ($i - 1);
            $left = ($left <= 16) ? $left : 16;
            $mask = ~(0xffff >> $left) & 0xffff;

            if (($bytesAddr[$i] & $mask) !== ($bytesTest[$i] & $mask)) {
                return false;
            }
        }

        return true;
    }
}
