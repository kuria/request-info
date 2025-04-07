<?php declare(strict_types=1);

namespace Kuria\RequestInfo;

use Kuria\DevMeta\Test;
use Kuria\RequestInfo\Exception\InvalidHostException;
use Kuria\RequestInfo\Exception\UntrustedHostException;
use Kuria\Url\Url;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @backupGlobals enabled
 */
class RequestInfoTest extends Test
{
    function testDefaultConfiguration()
    {
        RequestInfo::reset();

        $this->assertNull(RequestInfo::getTrustedProxies());
        $this->assertSame([], RequestInfo::getTrustedHosts());
        $this->assertSame([], RequestInfo::getTrustedHostPatterns());
        $this->assertFalse(RequestInfo::getAllowHttpMethodOverride());
    }

    function testShouldConfigureAndReset()
    {
        RequestInfo::reset();

        $trustedProxiesMock = $this->createMock(TrustedProxies::class);

        RequestInfo::setTrustedProxies($trustedProxiesMock);
        RequestInfo::setTrustedHosts(['example.com', 'localhost']);
        RequestInfo::setTrustedHostPatterns(['{\w+\.example\.com$}AD', '{\w+\.localhost}AD']);
        RequestInfo::setAllowHttpMethodOverride(true);

        $this->assertSame($trustedProxiesMock, RequestInfo::getTrustedProxies());
        $this->assertSame(['example.com', 'localhost'], RequestInfo::getTrustedHosts());
        $this->assertSame(['{\w+\.example\.com$}AD', '{\w+\.localhost}AD'], RequestInfo::getTrustedHostPatterns());
        $this->assertTrue(RequestInfo::getAllowHttpMethodOverride());

        RequestInfo::reset();

        $this->testDefaultConfiguration();
    }

    function testShouldGetHeaders()
    {
        $this->prepare([
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => 123,
            'HTTP_USER_AGENT' => 'test/1.0',
        ]);

        $this->assertCachedMethodCall(
            [
                'content-type' => 'application/json',
                'content-length' => 123,
                'user-agent' => 'test/1.0',
            ],
            'getHeaders'
        );

        $this->setServerValues([
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => 456,
        ]);

        RequestInfo::clearCache();

        $this->assertSame(
            [
                'content-type' => 'application/x-www-form-urlencoded',
                'content-length' => 456,
            ],
            RequestInfo::getHeaders(),
            'expected new headers after cache clear'
        );
    }

    function testShouldDetermineTrustedProxyWithoutTrustedProxies()
    {
        $this->prepare(['REMOTE_ADDR' => '10.20.30.40']);

        $this->assertCachedMethodCall(false, 'isFromTrustedProxy');
    }

    function testShouldDetermineTrustedProxyWithoutIpAddress()
    {
        $this->prepare();
        $this->prepareTrustedProxies(['10.20.30.40']);

        $this->assertCachedMethodCall(false, 'isFromTrustedProxy');
    }

    /**
     * @dataProvider provideIpsForTrustedProxyCheck
     */
    function testShouldDetermineTrustedProxy(string $ip, bool $isTrustedProxy)
    {
        $this->prepare(['REMOTE_ADDR' => $ip]);
        $trustedProxiesMock = $this->mockTrustedProxies();

        $trustedProxiesMock->expects($this->once())
            ->method('isIpFromTrustedProxy')
            ->with($ip)
            ->willReturn($isTrustedProxy);

        $this->assertCachedMethodCall($isTrustedProxy, 'isFromTrustedProxy');
    }

    function provideIpsForTrustedProxyCheck()
    {
        return [
            // ip, isFromTrustedProxy
            ['10.20.30.40', true],
            ['20.30.40.50', false],
        ];
    }

    /**
     * @dataProvider provideServerValuesForSecureDetermination
     */
    function testShouldDetermineSecureFromServerHttps(array $serverValues, bool $expectedSecure)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedSecure, 'isSecure');
    }

    function provideServerValuesForSecureDetermination()
    {
        return [
            // serverValues, expectedSecure
            'no server values' => [[], false],
            'blank https' => [['HTTPS' => ''], false],
            'https disabled' =>  [['HTTPS' => 'off'], false],
            'https disabled (case-insensitive)' => [['HTTPS' => 'OFF'], false],
            'https enabled' =>  [['HTTPS' => 'on'], true],
            'https enabled (case-insensitive)' =>  [['HTTPS' => 'ON'], true],
            'https enabled (non-standard value)' =>  [['HTTPS' => 'foo'], true],
            'untrusted forwarded' =>  [['HTTP_FORWARDED' => 'proto=https'], false],
            'untrusted x-forwarded' =>  [['HTTP_X_FORWARDED_PROTO' => 'https'], false],
        ];
    }

    /**
     * @dataProvider provideServerValuesForTrustedProxyHeaderSecureDetermination
     */
    function testShouldDetermineSecureFromTrustedProxyHeaders(array $serverValues, bool $expectedSecure)
    {
        $trustedProxyIp = '10.20.30.40';

        $this->prepare(['REMOTE_ADDR' => $trustedProxyIp] + $serverValues);
        $this->prepareTrustedProxies([$trustedProxyIp]);

        $this->assertCachedMethodCall($expectedSecure, 'isSecure');
    }

    function provideServerValuesForTrustedProxyHeaderSecureDetermination()
    {
        return [
            // serverValues, expectedSecure
            'no headers' => [
                [],
                false,
            ],

            'forwarded https' => [
                [
                    'HTTP_FORWARDED' => 'proto=https, proto=http',
                ],
                true,
            ],

            'forwarded http' => [
                [
                    'HTTP_FORWARDED' => 'proto=http, proto=https',
                ],
                false,
            ],

            'x-forwarded https' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'https, http',
                ],
                true,
            ],

            'x-forwarded https (on)' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'on, http',
                ],
                true,
            ],

            'x-forwarded https (ssl)' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'ssl, http',
                ],
                true,
            ],

            'x-forwarded https (1)' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => '1, http',
                ],
                true,
            ],

            'x-forwarded http' => [
                [
                    'HTTP_X_FORWARDED_PROTO' => 'http, https',
                ],
                false,
            ],
        ];
    }

    function testShouldDetermineSecureFromServerHttpsIfIpIsNotFromTrustedProxy()
    {
        $this->prepare(['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on']);
        $trustedProxiesMock = $this->mockTrustedProxies();

        $trustedProxiesMock->expects($this->once())
            ->method('isIpFromTrustedProxy')
            ->with('127.0.0.1')
            ->willReturn(false);

        $this->assertCachedMethodCall(true, 'isSecure');
    }

    function testShouldDetermineClientIps()
    {
        $this->prepare(['REMOTE_ADDR' => '1.2.3.4']);

        $this->assertCachedMethodCall(['1.2.3.4'], 'getClientIps');
        $this->assertSame('1.2.3.4', RequestInfo::getClientIp());
    }

    /**
     * @dataProvider provideServerValuesForTrustedProxyHeaderClientIpsDetermination
     */
    function testShouldDetermineClientIpsFromTrustedProxyHeaders(array $serverValues, array $expectedIps)
    {
        $trustedProxyIps = ['10.20.30.40', '20.30.40.50'];

        $this->prepare(['REMOTE_ADDR' => $trustedProxyIps[0]] + $serverValues);
        $this->prepareTrustedProxies($trustedProxyIps);

        $this->assertCachedMethodCall($expectedIps, 'getClientIps');
        $this->assertSame($expectedIps[0], RequestInfo::getClientIp());
    }

    function testShouldReturnBlankValueIfClientIpCannotBeDetermined()
    {
        $this->prepare();

        $this->assertCachedMethodCall([], 'getClientIps');
        $this->assertNull(RequestInfo::getClientIp());
    }

    function provideServerValuesForTrustedProxyHeaderClientIpsDetermination()
    {
        return [
            // serverValues, expectedIps
            'no headers' => [
                [],
                ['10.20.30.40'],
            ],

            'forwarded' => [
                ['HTTP_FORWARDED' => 'for=20.30.40.50, for=[2001:db8::1]:80, for=invalid, for=2002:7f00:1::2, for=2.3.4.5'],
                ['2.3.4.5', '2002:7f00:1::2', '2001:db8::1'],
            ],

            'x-forwarded' => [
                ['HTTP_X_FORWARDED_FOR' => '20.30.40.50:9090, [3.4.5.6], invalid, 7.8.9.10'],
                ['7.8.9.10', '3.4.5.6'],
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForMethodDetermination
     */
    function testShouldDetermineMethod(array $serverValues, string $expectedMethod)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedMethod, 'getMethod');
    }

    function provideServerValuesForMethodDetermination()
    {
        return [
            // serverValues, expectedMethod
            'no request method' => [
                [],
                'GET',
            ],

            'get' => [
                ['REQUEST_METHOD' => 'GET'],
                'GET',
            ],

            'post' => [
                ['REQUEST_METHOD' => 'POST'],
                'POST',
            ],

            'post with override (disabled)' => [
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PUT'],
                'POST',
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForMethodOverride
     */
    function testShouldOverrideMethodIfAllowed(array $serverValues, string $expectedMethod)
    {
        $this->prepare(['REQUEST_METHOD' => 'POST'] + $serverValues);
        RequestInfo::setAllowHttpMethodOverride(true);

        $this->assertCachedMethodCall($expectedMethod, 'getMethod');
    }

    function provideServerValuesForMethodOverride()
    {
        return [
            // serverValues, expectedMethod
            'no override' => [
                [],
                'POST',
            ],

            'override' => [
                ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'put'],
                'PUT',
            ],

            'custom override' => [
                ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'CuStOm'],
                'CUSTOM',
            ],

            'blank override' => [
                ['HTTP_X_HTTP_METHOD_OVERRIDE' => ''],
                'POST',
            ],

            'invalid override' => [
                ['HTTP_X_HTTP_METHOD_OVERRIDE' => '-+@#$~^&*{}'],
                'POST',
            ],

            'invalid override 2' => [
                ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'CUSTOM_FOO'],
                'POST',
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForSchemeDetermination
     */
    function testShouldDetermineScheme(array $serverValues, string $expectedScheme)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedScheme, 'getScheme');
    }

    function provideServerValuesForSchemeDetermination()
    {
        return [
            // serverValues, expectedScheme
            [[], 'http'],
            [['HTTPS' => 'on'], 'https'],
        ];
    }

    /**
     * @dataProvider provideServerValuesForHostDetermination
     */
    function testShouldDetermineHost(array $serverValues, string $expectedHost)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedHost, 'getHost');
    }

    function provideServerValuesForHostDetermination()
    {
        return [
            // serverValues, expectedHost
            'host header' =>  [['HTTP_HOST' => 'foo'], 'foo'],
            'server name' => [['SERVER_NAME' => 'bar'], 'bar'],
            'server addr' => [['SERVER_ADDR' => '1.2.3.4'], '1.2.3.4'],
            'fallback' => [[], 'localhost'],
            'untrusted forwarded' => [['HTTP_FORWARDED' => 'host=foo'], 'localhost'],
            'untrusted x-forwarded' => [['HTTP_X_FORWARDED_HOST' => 'bar'], 'localhost'],
        ];
    }

    /**
     * @dataProvider provideServerValuesForTrustedProxyHeaderHostDetermination
     */
    function testShouldDetermineHostFromTrustedProxyHeaders(array $serverValues, string $expectedHost)
    {
        $trustedProxyIp = '10.20.30.40';

        $this->prepare(['REMOTE_ADDR' => $trustedProxyIp] + $serverValues);
        $this->prepareTrustedProxies([$trustedProxyIp]);

        $this->assertCachedMethodCall($expectedHost, 'getHost');
    }

    function provideServerValuesForTrustedProxyHeaderHostDetermination()
    {
        return [
            // serverValues, expectedHost
            'no headers' => [
                [],
                'localhost',
            ],

            'forwarded' => [
                ['HTTP_FORWARDED' => 'by=1.2.3.4; host=foo, by=2.3.4.5; host=bar'],
                'foo',
            ],

            'x-forwarded' => [
                ['HTTP_X_FORWARDED_HOST' => 'baz, qux'],
                'baz',
            ],
        ];
    }

    function testShouldRejectInvalidHost()
    {
        $this->expectException(InvalidHostException::class);
        $this->expectExceptionMessage('Rejecting invalid host "123invalid++"');

        $this->prepare(['HTTP_HOST' => '123invalid++']);

        RequestInfo::getHost();
    }

    /**
     * @dataProvider provideTrustedHosts
     */
    function testShouldAcceptTrustedHost(array $trustedHosts, array $trustedHostPatterns, string $requestHost)
    {
        $this->prepare(['HTTP_HOST' => $requestHost]);

        RequestInfo::setTrustedHosts($trustedHosts);
        RequestInfo::setTrustedHostPatterns($trustedHostPatterns);

        $this->assertCachedMethodCall($requestHost, 'getHost');
    }

    /**
     * @dataProvider provideTrustedHosts
     */
    function testShouldRejectUntrustedHost(array $trustedHosts, array $trustedHostPatterns)
    {
        $this->prepare(['HTTP_HOST' => 'untrusted-host.example.com']);

        RequestInfo::setTrustedHosts($trustedHosts);
        RequestInfo::setTrustedHostPatterns($trustedHostPatterns);

        $this->expectException(UntrustedHostException::class);
        $this->expectExceptionMessage('Rejecting untrusted host "untrusted-host.example.com"');

        RequestInfo::getHost();
    }

    function provideTrustedHosts()
    {
        $combinedTrustedHostCase = [['foo', 'bar'], ['{baz$}AD', '{qu{1,2}[xz]}AD']];

        return [
            // trustedHosts, trustedHostPatterns, requestHost
            [['foo', 'bar'], [], 'foo'],
            [['baz', 'qux'], [], 'qux'],
            [[], ['{fo{2}$}AD'],  'foo'],
            [[], ['{fo{2}$}AD', '{b[a-z]r$}AD'],  'bar'],
            $combinedTrustedHostCase + [2 => 'foo'],
            $combinedTrustedHostCase + [2 => 'bar'],
            $combinedTrustedHostCase + [2 => 'baz'],
            $combinedTrustedHostCase + [2 => 'qux'],
            $combinedTrustedHostCase + [2 => 'quux'],
            $combinedTrustedHostCase + [2 => 'quuz'],
        ];
    }

    /**
     * @dataProvider provideServerValuesForPortDetermination
     */
    function testShouldDeterminePort(array $serverValues, int $expectedPort)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedPort, 'getPort');
    }

    function provideServerValuesForPortDetermination()
    {
        return [
            // serverValues, expectedPort
            'from host header' => [['HTTP_HOST' => 'foo:8080'], 8080],
            'from server name' => [['SERVER_NAME' => 'bar:9090'], 9090],
            'from server addr' => [['SERVER_ADDR' => '10.20.30.40:1010'], 1010],
            'from server port' => [['SERVER_PORT' => '2020'], 2020],
            'fallback with known host' => [['SERVER_PORT' => 123, 'SERVER_NAME' => 'baz'], 80],
            'secure fallback with known host' => [['SERVER_PORT' => 123, 'SERVER_NAME' => 'baz', 'HTTPS' => 'on'], 443],
            'fallback' => [[], 80],
            'secure fallback' => [['HTTPS' => 'on'], 443],
            'untrusted forwarded' => [['HTTP_FORWARDED' => 'host=foo:8080'], 80],
            'untrusted x-forwarded' => [['HTTP_X_FORWARDED_PORT' => '9090'], 80],
        ];
    }

    /**
     * @dataProvider provideServerValuesForTrustedProxyHeaderPortDetermination
     */
    function testShouldDeterminePortFromTrustedProxyHeaders(array $serverValues, int $expectedPort)
    {
        $trustedProxyIp = '10.20.30.40';

        $this->prepare(['REMOTE_ADDR' => $trustedProxyIp] + $serverValues);
        $this->prepareTrustedProxies([$trustedProxyIp]);

        $this->assertCachedMethodCall($expectedPort, 'getPort');
    }

    function provideServerValuesForTrustedProxyHeaderPortDetermination()
    {
        return [
            // serverValues, expectedPort
            'no headers' => [[], 80],
            'forwarded' => [['HTTP_FORWARDED' => 'host=foo:8080, host=bar:9090'], 8080],
            'x-forwarded' => [['HTTP_X_FORWARDED_PORT' => '8080, 9090'], 8080],
        ];
    }

    /**
     * @dataProvider provideServerValuesForUrlDetermination
     */
    function testShouldDetermineUrl(array $serverValues, Url $expectedUrl)
    {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedUrl, 'getUrl');
    }

    function provideServerValuesForUrlDetermination()
    {
        return [
            // serverValues, expectedUrl
            'no values' => [
                [],
                Url::parse('http://localhost/'),
            ],

            'REQUEST_URI' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'REQUEST_URI' => '/foo?bar=baz&qux=quux',
                    'QUERY_STRING' => 'quux=quuz',
                ],
                Url::parse('http://example.com/foo?bar=baz&qux=quux'),
            ],

            'IIS7 URL rewrite' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'IIS_WasUrlRewritten' => '1',
                    'UNENCODED_URL' => '/foo?bar=baz',
                ],
                Url::parse('http://example.com/foo?bar=baz'),
            ],

            'IIS5' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'ORIG_PATH_INFO' => '/foo',
                ],
                Url::parse('http://example.com/foo'),
            ],

            'IIS5 with empty query string' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'ORIG_PATH_INFO' => '/foo',
                    'QUERY_STRING' => '',
                ],
                Url::parse('http://example.com/foo'),
            ],

            'IIS5 with query string' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'ORIG_PATH_INFO' => '/foo',
                    'QUERY_STRING' => 'bar=baz&qux=quux'
                ],
                Url::parse('http://example.com/foo?bar=baz&qux=quux'),
            ],

            'standard port' => [
                [
                    'HTTP_HOST' => 'example.com:80',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('http://example.com/'),
            ],

            'standard port (secure)' => [
                [
                    'HTTP_HOST' => 'example.com:443',
                    'HTTPS' => 'on',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('https://example.com/'),
            ],

            'non-standard port' => [
                [
                    'HTTP_HOST' => 'example.com:8080',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('http://example.com:8080/'),
            ],

            'non-standard port (secure)' => [
                [
                    'HTTP_HOST' => 'example.com:9090',
                    'HTTPS' => 'on',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('https://example.com:9090/'),
            ],

            'path without initial slash' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'REQUEST_URI' => 'foo/bar',
                ],
                Url::parse('http://example.com/foo/bar'),
            ],

            'untrusted forwarded' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'HTTP_FORWARDED' => 'for=1.2.3.4; host=foo:8080; proto=https',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('http://example.com/'),
            ],

            'untrusted x-forwarded' => [
                [
                    'HTTP_HOST' => 'example.com',
                    'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
                    'HTTP_X_FORWARDED_HOST' => 'foo',
                    'HTTP_X_FORWARDED_PORT' => '8080',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('http://example.com/'),
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForTrustedProxyHeaderUrlDetermination
     */
    function testShouldDetermineUrlFromTrustedProxyHeaders(array $serverValues, Url $expectedUrl)
    {
        $trustedProxyIp = '10.20.30.40';

        $this->prepare(['REMOTE_ADDR' => $trustedProxyIp] + $serverValues);
        $this->prepareTrustedProxies([$trustedProxyIp]);

        $this->assertCachedMethodCall($expectedUrl, 'getUrl');
    }

    function provideServerValuesForTrustedProxyHeaderUrlDetermination()
    {
        return [
            // serverValues, expectedUrl
            'no headers' => [
                [
                    'HTTP_HOST' => 'node-1',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('http://node-1/'),
            ],

            'forwarded' => [
                [
                    'HTTP_HOST' => 'node-1',
                    'HTTP_FORWARDED' => 'for=1.2.3.4; host=foo:8080; proto=https',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('https://foo:8080/'),
            ],

            'x-forwarded' => [
                [
                    'HTTP_HOST' => 'node-1',
                    'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
                    'HTTP_X_FORWARDED_HOST' => 'foo',
                    'HTTP_X_FORWARDED_PORT' => '8080',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                    'REQUEST_URI' => '/',
                ],
                Url::parse('https://foo:8080/'),
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForPathDetermination
     */
    function testShouldDeterminePaths(
        array $serverValues,
        string $expectedBasePath,
        string $expectedBaseDir,
        string $expectedPathInfo
    ) {
        $this->prepare($serverValues);

        $this->assertCachedMethodCall($expectedBasePath, 'getBasePath');
        $this->assertCachedMethodCall($expectedBaseDir, 'getBaseDir');
        $this->assertCachedMethodCall($expectedPathInfo, 'getPathInfo');
    }

    function provideServerValuesForPathDetermination()
    {
        return [
            [
                'serverValues' => [],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/fruit/strawberry/1234index.php/blah',
                    'SCRIPT_FILENAME' => 'E:/Sites/cc-new/public_html/fruit/index.php',
                    'SCRIPT_NAME' => '/fruit/index.php',
                    'PHP_SELF' => '/fruit/index.php',
                ],
                'expectedBasePath' => '/fruit',
                'expectedBaseDir' => '/fruit',
                'expectedPathInfo' => '/strawberry/1234index.php/blah',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/fruit/strawberry/1234index.php/blah',
                    'SCRIPT_FILENAME' => 'E:/Sites/cc-new/public_html/index.php',
                    'SCRIPT_NAME' => '/index.php',
                    'PHP_SELF' => '/index.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/fruit/strawberry/1234index.php/blah',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/foo%20bar/',
                    'SCRIPT_FILENAME' => '/home/John Doe/public_html/foo bar/app.php',
                    'SCRIPT_NAME' => '/foo bar/app.php',
                    'PHP_SELF' => '/foo bar/app.php',
                ],
                'expectedBasePath' => '/foo%20bar',
                'expectedBaseDir' => '/foo%20bar',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/foo%20bar/home',
                    'SCRIPT_FILENAME' => '/home/John Doe/public_html/foo bar/app.php',
                    'SCRIPT_NAME' => '/foo bar/app.php',
                    'PHP_SELF' => '/foo bar/app.php',
                ],
                'expectedBasePath' => '/foo%20bar',
                'expectedBaseDir' => '/foo%20bar',
                'expectedPathInfo' => '/home',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/foo%20bar/app.php/home',
                    'SCRIPT_FILENAME' => '/home/John Doe/public_html/foo bar/app.php',
                    'SCRIPT_NAME' => '/foo bar/app.php',
                    'PHP_SELF' => '/foo bar/app.php',
                ],
                'expectedBasePath' => '/foo%20bar/app.php',
                'expectedBaseDir' => '/foo%20bar',
                'expectedPathInfo' => '/home',
            ],
            [
               'serverValues' => [
                    'REQUEST_URI' => '/foo%20bar/app.php/home%3Dbaz',
                    'SCRIPT_FILENAME' => '/home/John Doe/public_html/foo bar/app.php',
                    'SCRIPT_NAME' => '/foo bar/app.php',
                    'PHP_SELF' => '/foo bar/app.php',
                ],
               'expectedBasePath' => '/foo%20bar/app.php',
               'expectedBaseDir' => '/foo%20bar',
               'expectedPathInfo' => '/home%3Dbaz',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/foo/bar+baz',
                    'SCRIPT_FILENAME' => '/home/John Doe/public_html/foo/app.php',
                    'SCRIPT_NAME' => '/foo/app.php',
                    'PHP_SELF' => '/foo/app.php',
                ],
                'expectedBasePath' => '/foo',
                'expectedBaseDir' => '/foo',
                'expectedPathInfo' => '/bar+baz',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => 'hello/app.php/x',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '/hello/app.php',
                'expectedBaseDir' => '/hello',
                'expectedPathInfo' => '/x',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => 'hello/x',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '/hello',
                'expectedBaseDir' => '/hello',
                'expectedPathInfo' => '/x',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/hello/x',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '/hello',
                'expectedBaseDir' => '/hello',
                'expectedPathInfo' => '/x',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => 'hello/app.php/x',
                    'SCRIPT_FILENAME' => '/some/path/app.php',
                    'PHP_SELF' => '/hello/app.php',
                ],
                'expectedBasePath' => '/hello/app.php',
                'expectedBaseDir' => '/hello',
                'expectedPathInfo' => '/x',
            ],
            [
                'serverValues' => [
                    'SCRIPT_FILENAME' => '/some/where/index.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'SCRIPT_FILENAME' => '/some/where/index.php',
                    'SCRIPT_NAME' => '/index.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'SCRIPT_FILENAME' => '/some/where/index.php',
                    'PHP_SELF' => '/index.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'SCRIPT_FILENAME' => '/some/where/index.php',
                    'ORIG_SCRIPT_NAME' => '/index.php',
                ],
                'expectedBasePath' => '',
                'expectedBaseDir' => '',
                'expectedPathInfo' => '/',
            ],
            [
                'serverValues' => [
                    'REQUEST_URI' => '/comments/site/index.php',
                    'SCRIPT_FILENAME' => '/site/index.php',
                    'SCRIPT_NAME' => '/site/index.php',
                    'PHP_SELF' => '/site/index.php',
                ],
                'expectedBasePath' => '/comments/site/index.php',
                'expectedBaseDir' => '/comments/site',
                'expectedPathInfo' => '',
            ],
        ];
    }

    /**
     * @dataProvider provideServerValuesForScriptNameDetermination
     */
    function testShouldDetermineScriptName(array $serverValues, string $expectedScriptName)
    {
        $this->prepare($serverValues);

        $this->assertSame($expectedScriptName, RequestInfo::getScriptName());
    }

    function provideServerValuesForScriptNameDetermination()
    {
        return [
            // serverValues, expectedScriptName
            [['SCRIPT_NAME' => '/src/foo.php'], '/src/foo.php'],
            [['ORIG_SCRIPT_NAME' => '/bar.php'], '/bar.php'],
            [[], ''],
        ];
    }

    private function assertCachedMethodCall($expectedResult, string $method, ...$args): void
    {
        $serverValuesBackup = $_SERVER;

        $this->assertLooselyIdentical(
            $expectedResult,
            RequestInfo::{$method}(...$args),
            false,
            "expected correct result from RequestInfo::{$method}()"
        );

        $this->setServerValues([]);

        $this->assertLooselyIdentical(
            $expectedResult,
            RequestInfo::{$method}(...$args),
            false,
            "expected cached result from RequestInfo::{$method}()"
        );

        $this->setServerValues($serverValuesBackup);
    }

    private function prepare(array $serverValues = []): void
    {
        RequestInfo::reset();
        $this->setServerValues($serverValues);
    }

    private function setServerValues(array $serverValues): void
    {
        $_SERVER = $serverValues;
    }

    /**
     * @return TrustedProxies|MockObject
     */
    private function mockTrustedProxies(): TrustedProxies
    {
        $trustedProxies = $this->createMock(TrustedProxies::class);

        RequestInfo::setTrustedProxies($trustedProxies);

        return $trustedProxies;
    }

    private function prepareTrustedProxies(array $trustedProxies): void
    {
        RequestInfo::setTrustedProxies(
            new TrustedProxies(
                $trustedProxies,
                TrustedProxies::HEADER_FORWARDED | TrustedProxies::HEADER_X_FORWARDED_ALL
            )
        );
    }
}
