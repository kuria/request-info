<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

use Kuria\DevMeta\Test;
use Kuria\RequestInfo\Exception\UnsupportedOperationException;
use phpmock\phpunit\PHPMock;

class IpMatcherTest extends Test
{
    use PHPMock;

    /**
     * @dataProvider provideIpv4
     */
    function testShouldMatchIpv4(string $ip, array $list, bool $expectedResult)
    {
        $this->assertSame($expectedResult, IpMatcher::match($ip, $list));
    }

    function provideIpv4()
    {
        return [
            // ip, list, expectedResult
            ['192.168.1.1', ['192.168.1.1'], true],
            ['192.168.1.1', ['192.168.1.1/1'], true],
            ['192.168.1.1', ['192.168.1.0/24'], true],
            ['192.168.1.1', ['1.2.3.4/1'], false],
            ['192.168.1.1', ['192.168.1.1/33'], false], // invalid subnet,
            ['192.168.1.1', ['1.2.3.4/1', '192.168.1.0/24'], true],
            ['192.168.1.1', ['192.168.1.0/24', '1.2.3.4/1'], true],
            ['192.168.1.1', ['1.2.3.4/1', '4.3.2.1/1'], false],
            ['1.2.3.4', ['0.0.0.0/0'], true],
            ['1.2.3.4', ['192.168.1.0/0'], true],
            ['1.2.3.4', ['256.256.256/0'], false], // invalid CIDR notation
            ['an_invalid_ip', ['192.168.1.0/24'], false],
        ];
    }

    /**
     * @dataProvider provideIpv6
     */
    function testShouldMatchIpv6(string $ip, array $list, bool $expectedResult)
    {
        $this->assertSame($expectedResult, IpMatcher::match($ip, $list));
    }

    function provideIpv6()
    {
        return [
            // ip, list, expectedResult
            ['2a01:198:603:0:396e:4789:8e99:890f', ['2a01:198:603:0::/65'], true],
            ['2a00:198:603:0:396e:4789:8e99:890f', ['2a01:198:603:0::/65'], false],
            ['2a01:198:603:0:396e:4789:8e99:890f', ['::1'], false],
            ['0:0:0:0:0:0:0:1', ['::1'], true],
            ['0:0:603:0:396e:4789:8e99:0001', ['::1'], false],
            ['0:0:603:0:396e:4789:8e99:0001', ['::/0'], true],
            ['0:0:603:0:396e:4789:8e99:0001', ['2a01:198:603:0::/0'], true],
            ['2a01:198:603:0:396e:4789:8e99:890f', ['::1', '2a01:198:603:0::/65'], true],
            ['2a01:198:603:0:396e:4789:8e99:890f', ['2a01:198:603:0::/65', '::1'], true],
            ['2a01:198:603:0:396e:4789:8e99:890f', ['::1', '1a01:198:603:0::/65'], false],
            ['}__test|O:21:&quot;JDatabaseDriverMysqli&quot;:3:{s:2', ['::1'], false],
            ['2a01:198:603:0:396e:4789:8e99:890f', ['unknown'], false],
        ];
    }

    /**
     * @dataProvider provideInvalidIpv4
     */
    function testShouldNotMatchInvalidIpv4($ip, $invalidIp)
    {
        $this->assertFalse(IpMatcher::matchIp4($ip, $invalidIp));
    }

    function provideInvalidIpv4()
    {
        return [
            'invalid proxy wildcard' => ['192.168.20.13', '*'],
            'invalid proxy missing netmask' => ['192.168.20.13', '0.0.0.0'],
            'invalid request IP with invalid proxy wildcard' => ['0.0.0.0', '*'],
        ];
    }
}
