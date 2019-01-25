<?php declare(strict_types=1);

namespace Kuria\RequestInfo;

use Kuria\DevMeta\Test;
use Kuria\RequestInfo\Exception\HeaderConflictException;

class TrustedProxiesTest extends Test
{
    function testShouldCreateTrustedProxies()
    {
        $trustedProxies = new TrustedProxies(['10.20.30.40', '20.30.40.50'], TrustedProxies::HEADER_X_FORWARDED_ALL);

        $this->assertSame(['10.20.30.40', '20.30.40.50'], $trustedProxies->getTrustedProxies());
        $this->assertSame(TrustedProxies::HEADER_X_FORWARDED_ALL, $trustedProxies->getTrustedProxyHeaders());
    }

    /**
     * @dataProvider provideIpsForTrustedProxyCheck
     */
    function testShouldDetectTrustedProxyIp(string $ip, array $trustedProxies, bool $expectedResult)
    {
        $trustedProxies = new TrustedProxies($trustedProxies);

        $this->assertSame($expectedResult, $trustedProxies->isIpFromTrustedProxy($ip));
    }

    function provideIpsForTrustedProxyCheck()
    {
        return [
            // ip, trustedProxies, expectedResult
            ['192.168.1.1', ['192.168.1.1'], true],
            ['192.168.1.1', ['192.168.1.0/24'], true],
            ['192.168.1.1', ['1.2.3.4/1', '4.3.2.1/1'], false],
            ['127.0.0.1', [], false],
        ];
    }

    function testShouldDetectTrustedProxyHeader()
    {
        $trustedProxies = new TrustedProxies([], TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_HOST);

        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_FORWARDED));
        $this->assertFalse($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_FOR));
        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_HOST));
        $this->assertFalse($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_PROTO));
        $this->assertFalse($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_PORT));
    }

    function testShouldTrustAllXforwardedHeaders()
    {
        $trustedProxies = new TrustedProxies([], TrustedProxies::HEADER_X_FORWARDED_ALL);

        $this->assertFalse($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_FORWARDED));
        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_FOR));
        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_HOST));
        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_PROTO));
        $this->assertTrue($trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_X_FORWARDED_PORT));
    }

    /**
     * @dataProvider provideGetForwardedPropValuesArgs
     */
    function testShouldGetForwardedPropValues(
        int $trustedProxyHeaders,
        array $headers,
        ?array $forwardedHeaderGroups,
        array $expectedPropValueMap
    ) {
        $trustedProxies = new TrustedProxies([], $trustedProxyHeaders);

        $actualPropValueMap = [];

        foreach (array_keys($expectedPropValueMap) as $prop) {
            $actualPropValueMap[$prop] = $trustedProxies->getForwardedPropValues($headers, $forwardedHeaderGroups, $prop);
        }

        $this->assertSame($expectedPropValueMap, $actualPropValueMap);
    }

    function provideGetForwardedPropValuesArgs()
    {
        return [
            'no headers' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_ALL,
                'headers' => [],
                'forwardedHeaderGroups' => null,
                'expectedPropValueMap' => [
                    'for' => [],
                    'host' => [],
                    'port' => [],
                    'proto' => [],
                ],
            ],

            'no trusted headers' => [
                'trustedProxyHeaders' => 0,
                'headers' => [
                    'x-forwarded-for' => '10.20.30.40, 20.30.40.50',
                    'x-forwarded-host' => 'foo, bar',
                    'x-forwarded-port' => '123, 456',
                    'x-forwarded-proto' => 'baz, qux',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['for' => '110.120.130.140', 'host' => 'lorem:8080', 'proto' => 'https']),
                    $this->createForwardedHeaderGroup(['for' => '120.130.140.150', 'host' => 'ipsum:9090', 'proto' => 'http']),
                ],
                'expectedPropValueMap' => [
                    'for' => [],
                    'host' => [],
                    'port' => [],
                    'proto' => [],
                ],
            ],

            'forwarded only' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_FORWARDED,
                'headers' => [
                    'x-forwarded-for' => '10.20.30.40, 20.30.40.50',
                    'x-forwarded-host' => 'foo, bar',
                    'x-forwarded-port' => '123, 456',
                    'x-forwarded-proto' => 'baz, qux',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['for' => '110.120.130.140', 'host' => 'lorem:8080', 'proto' => 'https']),
                    $this->createForwardedHeaderGroup(['for' => '120.130.140.150', 'host' => 'ipsum:9090', 'proto' => 'http']),
                ],
                'expectedPropValueMap' => [
                    'for' => ['110.120.130.140', '120.130.140.150'],
                    'host' => ['lorem', 'ipsum'],
                    'port' => [8080, 9090],
                    'proto' => ['https', 'http'],
                ],
            ],

            'x-forwarded only' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_X_FORWARDED_ALL,
                'headers' => [
                    'x-forwarded-for' => '10.20.30.40, 20.30.40.50',
                    'x-forwarded-host' => 'lorem, ipsum',
                    'x-forwarded-port' => '8080, 9090',
                    'x-forwarded-proto' => 'https, http',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['for' => '110.120.130.140', 'host' => 'foo:123', 'proto' => 'baz']),
                    $this->createForwardedHeaderGroup(['for' => '120.130.140.150', 'host' => 'bar:456', 'proto' => 'qux']),
                ],
                'expectedPropValueMap' => [
                    'for' => ['10.20.30.40', '20.30.40.50'],
                    'host' => ['lorem', 'ipsum'],
                    'port' => [8080, 9090],
                    'proto' => ['https', 'http'],
                ],
            ],

            'both forwarded and x-forwarded' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_ALL,
                'headers' => [
                    'x-forwarded-for' => '10.20.30.40, 20.30.40.50',
                    'x-forwarded-host' => 'lorem, ipsum',
                    'x-forwarded-port' => '8080, 9090',
                    'x-forwarded-proto' => 'https, http',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['for' => '10.20.30.40', 'host' => 'lorem:8080', 'proto' => 'https']),
                    $this->createForwardedHeaderGroup(['for' => '20.30.40.50', 'host' => 'ipsum:9090', 'proto' => 'http']),
                ],
                'expectedPropValueMap' => [
                    'for' => ['10.20.30.40', '20.30.40.50'],
                    'host' => ['lorem', 'ipsum'],
                    'port' => [8080, 9090],
                    'proto' => ['https', 'http'],
                ],
            ],

            'forwarded port fallback' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_FORWARDED,
                'headers' => [],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['host' => 'foo']),
                    $this->createForwardedHeaderGroup(['host' => 'bar', 'proto' => 'http']),
                    $this->createForwardedHeaderGroup(['host' => 'baz', 'proto' => 'https']),
                    $this->createForwardedHeaderGroup(['host' => 'qux:8080', 'proto' => 'https']),
                    $this->createForwardedHeaderGroup(['host' => 'quuz:nonsense']),
                ],
                'expectedPropValueMap' => [
                    'host' => ['foo', 'bar', 'baz', 'qux', 'quuz:nonsense'],
                    'port' => [80, 80, 443, 8080, 80],
                ],
            ],

            'forwarded null props' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_FORWARDED,
                'headers' => [],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup([]),
                    $this->createForwardedHeaderGroup(['for' => '110.120.130.140', 'host' => 'ipsum:8080', 'proto' => 'https']),
                ],
                'expectedPropValueMap' => [
                    'for' => ['', '110.120.130.140'],
                    'host' => ['', 'ipsum'],
                    'port' => [80, 8080],
                    'proto' => ['', 'https'],
                ],
            ],

            'x-forwarded value sanitization' => [
                'trustedProxyHeaders' => TrustedProxies::HEADER_X_FORWARDED_ALL,
                'headers' => [
                    'x-forwarded-for' => '   10.20.30.40, ,   20.30.40.50   ',
                    'x-forwarded-host' => '  lorem,,ipsum ',
                    'x-forwarded-port' => '8080,,nonsense, 9090 ',
                    'x-forwarded-proto' => 'https , http,,',
                ],
                'forwardedHeaderGroups' => null,
                'expectedPropValueMap' => [
                    'for' => ['10.20.30.40', '', '20.30.40.50'],
                    'host' => ['lorem', '', 'ipsum'],
                    'port' => [8080, 9090],
                    'proto' => ['https', 'http', '', ''],
                ],
            ],
        ];
    }

    function testShouldFilterForwardedHeaderProps()
    {
        $filterCallCount = 0;

        $trustedProxies = new TrustedProxies([], TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_ALL);

        $values = $trustedProxies->getForwardedPropValues(
            ['x-forwarded-for' => '10.20.30.40, 20.30.40.50'],
            [
                $this->createForwardedHeaderGroup(['for' => '10.20.30.40']),
                $this->createForwardedHeaderGroup(['for' => '20.30.40.50']),
            ],
            'for',
            static function (array $values) use (&$filterCallCount) {
                ++$filterCallCount;

                static::assertSame(['10.20.30.40', '20.30.40.50'], $values);

                $values[] = '127.0.0.1';

                return $values;
            }
        );

        $this->assertSame(['10.20.30.40', '20.30.40.50', '127.0.0.1'], $values);
        $this->assertSame(2, $filterCallCount);
    }

    /**
     * @dataProvider provideConflictingHeaders
     */
    function testShouldDetectHeaderConflict(array $headers, array $forwardedHeaderGroups, string $prop, string $expectedMessage)
    {
        $trustedProxies = new TrustedProxies([], TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_ALL);

        $this->expectException(HeaderConflictException::class);
        $this->expectExceptionMessage($expectedMessage);

        $trustedProxies->getForwardedPropValues($headers, $forwardedHeaderGroups, $prop);
    }

    function provideConflictingHeaders()
    {
        return [
            [
                'headers' => [
                    'x-forwarded-for' => '10.20.30.40, 20.30.40.50',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['for' => '10.20.30.40']),
                    $this->createForwardedHeaderGroup(['for' => '110.120.130.140']),
                ],
                'prop' => 'for',
                'expectedMessage' => 'header "forwarded" and trusted header "x-forwarded-for",'
                    . ' but they report different values for the property "for".'
                    . "\n\nforwarded: 10.20.30.40, 110.120.130.140"
                    . "\nx-forwarded-for: 10.20.30.40, 20.30.40.50",
            ],
            [
                'headers' => [
                    'x-forwarded-host' => 'lorem, ipsum',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['host' => 'lorem:8080']),
                    $this->createForwardedHeaderGroup(['host' => 'dummy:9090']),
                ],
                'prop' => 'host',
                'expectedMessage' => 'header "forwarded" and trusted header "x-forwarded-host",'
                    . ' but they report different values for the property "host".'
                    . "\n\nforwarded: lorem, dummy"
                    . "\nx-forwarded-host: lorem, ipsum",
            ],
            [
                'headers' => [
                    'x-forwarded-port' => '8080, 9090',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['host' => 'lorem:8080']),
                    $this->createForwardedHeaderGroup(['host' => 'ipsum:1234']),
                ],
                'prop' => 'port',
                'expectedMessage' => 'header "forwarded" and trusted header "x-forwarded-port",'
                    . ' but they report different values for the property "port".'
                    . "\n\nforwarded: 8080, 1234"
                    . "\nx-forwarded-port: 8080, 9090",
            ],
            [
                'headers' => [
                    'x-forwarded-proto' => 'https, http',
                ],
                'forwardedHeaderGroups' => [
                    $this->createForwardedHeaderGroup(['proto' => 'https']),
                    $this->createForwardedHeaderGroup(['proto' => 'dummy']),
                ],
                'prop' => 'proto',
                'expectedMessage' => 'header "forwarded" and trusted header "x-forwarded-proto",'
                    . ' but they report different values for the property "proto".'
                    . "\n\nforwarded: https, dummy"
                    . "\nx-forwarded-proto: https, http",
            ],
        ];
    }

    function testShouldThrowExceptionWhenGettingInvalidForwardedHeaderProp()
    {
        $trustedProxies = new TrustedProxies([]);

        $this->expectException(\OutOfBoundsException::class);
        $this->expectExceptionMessage('Invalid prop "__invalid__"');

        $trustedProxies->getForwardedPropValues([], null, '__invalid__');
    }

    private function createForwardedHeaderGroup(array $props): array
    {
        return $props + ['by' => null, 'for' => null, 'host' => null, 'proto' => null];
    }
}
