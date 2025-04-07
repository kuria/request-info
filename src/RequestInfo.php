<?php declare(strict_types=1);

namespace Kuria\RequestInfo;

use Kuria\RequestInfo\Exception\InvalidHostException;
use Kuria\RequestInfo\Exception\UntrustedHostException;
use Kuria\RequestInfo\Helper\Parser;
use Kuria\RequestInfo\Helper\Server;
use Kuria\Url\Url;

/**
 * Get information about the current HTTP request
 */
abstract class RequestInfo
{
    /** @var TrustedProxies|null */
    private static $trustedProxies;

    /** @var string[] */
    private static $trustedHosts = [];

    /** @var string[] */
    private static $trustedHostPatterns = [];

    /** @var bool */
    private static $allowHttpMethodOverride = false;

    /** @var array */
    private static $cache = [];

    /**
     * Reset all configuration to factory settings
     */
    static function reset()
    {
        self::$trustedProxies = null;
        self::$trustedHosts = [];
        self::$trustedHostPatterns = [];
        self::$allowHttpMethodOverride = false;
        self::$cache = [];
    }

    /**
     * Clear internally cached values (e.g. after $_SERVER has been manipulated)
     */
    static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Define trusted proxies
     */
    static function setTrustedProxies(?TrustedProxies $trustedProxies): void
    {
        self::$trustedProxies = $trustedProxies;
        static::clearCache();
    }

    static function getTrustedProxies(): ?TrustedProxies
    {
        return self::$trustedProxies;
    }

    /**
     * Set a list of trusted hostnames
     *
     * @param string[] $trustedHosts
     */
    static function setTrustedHosts(array $trustedHosts): void
    {
        self::$trustedHosts = $trustedHosts;
        static::clearCache();
    }

    /**
     * @return string[]
     */
    static function getTrustedHosts(): array
    {
        return self::$trustedHosts;
    }

    /**
     * Set a list of trusted hostname regex patterns
     *
     * Example: ['{(www\.)?example.com$}AD', '{\w+\.localhost$}AD]
     *
     * @param string[] $trustedHostPatterns
     */
    static function setTrustedHostPatterns(array $trustedHostPatterns): void
    {
        self::$trustedHostPatterns = $trustedHostPatterns;
        static::clearCache();
    }

    /**
     * @return string[]
     */
    static function getTrustedHostPatterns(): array
    {
        return self::$trustedHostPatterns;
    }

    /**
     * Set whether the X-Http-Method-Override header should be recognized
     */
    static function setAllowHttpMethodOverride(bool $allowHttpMethodOverride): void
    {
        self::$allowHttpMethodOverride = $allowHttpMethodOverride;
    }

    static function getAllowHttpMethodOverride(): bool
    {
        return self::$allowHttpMethodOverride;
    }

    /**
     * Get header map
     *
     * Header names are lowercased.
     */
    static function getHeaders(): array
    {
        return self::$cache['headers'] ?? (self::$cache['headers'] = Server::getHeaders());
    }

    /**
     * Check whether the request originated from a trusted proxy
     */
    static function isFromTrustedProxy(): bool
    {
        return self::$cache['from_trusted_proxy'] ?? (self::$cache['from_trusted_proxy'] = self::determineIsFromTrustedProxy());
    }

    /**
     * See whether the request uses HTTPS
     */
    static function isSecure(): bool
    {
        return self::$cache['secure'] ?? (self::$cache['secure'] = self::determineSecure());
    }

    /**
     * Get the client IP address
     */
    static function getClientIp(): ?string
    {
        return static::getClientIps()[0] ?? null;
    }

    /**
     * Get the client IP addresses
     *
     * - the most trusted IP address is listed first and the least trusted one last
     * - trusted proxies are stripped
     */
    static function getClientIps(): array
    {
        return self::$cache['client_ips'] ?? (self::$cache['client_ips'] = self::determineClientIps());
    }

    /**
     * Get the request method
     *
     * @see RequestInfo::setAllowHttpMethodOverride()
     */
    static function getMethod(): string
    {
        return self::$cache['method'] ?? (self::$cache['method'] = self::determineMethod());
    }

    /**
     * Get the request scheme
     */
    static function getScheme(): string
    {
        return static::isSecure() ? 'https' : 'http';
    }

    /**
     * Get the host
     */
    static function getHost(): string
    {
        return self::$cache['host'] ?? (self::$cache['host'] = self::determineHost());
    }

    /**
     * Get the port number
     */
    static function getPort(): int
    {
        return self::$cache['port'] ?? (self::$cache['port'] = self::determinePort());
    }

    /**
     * Get the request URL
     *
     * Returns an unique instance each time.
     */
    static function getUrl(): Url
    {
        return clone (self::$cache['url'] ?? (self::$cache['url'] = self::determineUrl()));
    }

    /**
     * Get base directory (without script name, if any)
     *
     * It never ends with with a /.
     *
     * Examples:
     *
     * - http://localhost/index.php => ''
     * - http://localhost/index.php/page => ''
     * - http://localhost/web/index.php => '/web'
     * - http://localhost/we%20b/index.php => '/we%20b'
     */
    static function getBaseDir(): string
    {
        return self::$cache['base_dir'] ?? (self::$cache['base_dir'] = self::determineBaseDir());
    }

    /**
     * Get base path (including the script name, if any)
     *
     * It never ends with with a /.
     *
     * Examples:
     *
     * - http://localhost/index.php => '/index.php'
     * - http://localhost/index.php/page => '/index.php'
     * - http://localhost/web/index.php => '/web/index.php'
     * - http://localhost/we%20b/index.php => '/we%20b/index.php'
     */
    static function getBasePath(): string
    {
        return self::$cache['base_path'] ?? (self::$cache['base_path'] = self::determineBasePath());
    }

    /**
     * Get path info
     *
     * Examples:
     *
     * - http://localhost/index.php => ''
     * - http://localhost/index.php/page => '/page'
     * - http://localhost/web/index.php => ''
     * - http://localhost/we%20b/index.php/foo%20bar => '/foo%20bar'
     */
    static function getPathInfo(): string
    {
        return self::$cache['path_info'] ?? (self::$cache['path_info'] = self::determinePathInfo());
    }

    /**
     * Get the current script name
     */
    static function getScriptName(): string
    {
        return Server::get('SCRIPT_NAME') ?? Server::get('ORIG_SCRIPT_NAME') ?? '';
    }

    private static function determineIsFromTrustedProxy(): bool
    {
        if (!self::$trustedProxies) {
            return false;
        }

        $ip = Server::get('REMOTE_ADDR');

        if ($ip === null) {
            return false;
        }

        return self::$trustedProxies->isIpFromTrustedProxy($ip);
    }

    private static function determineSecure(): bool
    {
        if (static::isFromTrustedProxy() && ($proto = self::getForwardedPropValues('proto'))) {
            return in_array(strtolower($proto[0]), ['https', 'on', 'ssl', '1'], true);
        }

        $https = Server::get('HTTPS');

        return !empty($https) && strtolower($https) !== 'off';
    }

    private static function determineClientIps(): array
    {
        $ip = Server::get('REMOTE_ADDR');

        if ($ip === null) {
            return [];
        }

        if (!static::isFromTrustedProxy()) {
            return [$ip];
        }

        $forwardedIps = self::getForwardedPropValues(
            'for',
            function (array $forwardedIps) use ($ip) {
                $forwardedIps[] = $ip;

                return self::normalizeAndFilterClientIps($forwardedIps);
            }
        );

        if ($forwardedIps) {
            return array_reverse($forwardedIps);
        }

        return [$ip];
    }

    private static function determineMethod(): string
    {
        $method = strtoupper(Server::get('REQUEST_METHOD') ?? 'GET');

        if (
            $method === 'POST'
            && self::$allowHttpMethodOverride
            && ($methodOverride = static::getHeaders()['x-http-method-override'] ?? null) !== null
            && preg_match('{[A-Za-z]++$}AD', $methodOverride)
        ) {
            $method = strtoupper($methodOverride);
        }

        return $method;
    }

    private static function determineHost(): string
    {
        // get current host without port
        $host = self::getRawHost();

        if ($host !== null) {
            $host = Parser::parseHostAndPort($host)['host'];
        } else {
            $host = 'localhost';
        }

        // validate
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidHostException(sprintf('Rejecting invalid host "%s"', $host));
        }

        if (!self::isTrustedHost($host)) {
            throw new UntrustedHostException(sprintf('Rejecting untrusted host "%s"', $host));
        }

        return $host;
    }

    private static function getRawHost(): ?string
    {
        $host = (static::isFromTrustedProxy() ? (self::getForwardedPropValues('host')[0] ?? null) : null)
            ?? static::getHeaders()['host']
            ?? Server::get('SERVER_NAME')
            ?? Server::get('SERVER_ADDR')
            ?? null;

        if ($host !== null) {
            return (string) $host;
        }

        return null;
    }

    private static function isTrustedHost(string $host): bool
    {
        // if no trusted hosts are configured, then the host is always trusted
        if (empty(self::$trustedHosts) && empty(self::$trustedHostPatterns)) {
            return true;
        }

        // attempt to match configured trusted hosts
        if (self::$trustedHosts && in_array($host, self::$trustedHosts, true)) {
            return true;
        }

        // attempt to match configured trusted host patterns
        foreach (self::$trustedHostPatterns as $trustedHostPattern) {
            if (preg_match($trustedHostPattern, $host)) {
                return true;
            }
        }

        // failure
        return false;
    }

    private static function determinePort(): int
    {
        if (static::isFromTrustedProxy() && ($ports = self::getForwardedPropValues('port'))) {
            $port = $ports[0];
        } elseif (($host = self::getRawHost()) !== null) {
            $port = Parser::parseHostAndPort($host)['port'];
        } elseif ($serverPort = Server::get('SERVER_PORT')) {
            $port = (int) $serverPort;
        }

        return $port ?? (static::isSecure() ? 443 : 80);
    }

    private static function determineUrl(): Url
    {
        $url = '';

        if (Server::get('IIS_WasUrlRewritten') && Server::hasNotEmpty('UNENCODED_URL')) {
            // IIS7 with URL rewrite
            $url = Server::require('UNENCODED_URL');
        } elseif (Server::has('REQUEST_URI')) {
            // standard
            $url = Server::require('REQUEST_URI');
        } elseif (Server::has('ORIG_PATH_INFO')) {
            // IIS 5.0, PHP as CGI
            $url = Server::require('ORIG_PATH_INFO');

            if (Server::hasNotEmpty('QUERY_STRING')) {
                $url .= '?' . Server::require('QUERY_STRING');
            }
        }

        $url = Url::parse($url);
        $url->setHost(static::getHost());
        $url->setScheme(static::getScheme());

        // set port if it's non-standard
        $port = static::getPort();

        if (static::isSecure() && $port !== 443 || !static::isSecure() && $port !== 80) {
            $url->setPort($port);
        }

        // normalize the path
        $path = $url->getPath();

        if ($path === '' || $path[0] !== '/') {
            $url->setPath("/{$path}");
        }

        return $url;
    }

    private static function determineBaseDir(): string
    {
        $basePath = static::getBasePath();

        if ($basePath === '') {
            return '';
        }

        $filename = basename(Server::get('SCRIPT_FILENAME') ?? '');

        if (basename($basePath) === $filename) {
            $baseDir = dirname($basePath);
        } else {
            $baseDir = $basePath;
        }

        return rtrim(strtr($baseDir, '\\', '/'), '/');
    }

    private static function determineBasePath(): string
    {
        $scriptFileName = Server::get('SCRIPT_FILENAME') ?? '';
        $baseScriptFileName = basename($scriptFileName);
        $scriptName = static::getScriptName();
        $requestPath = static::getUrl()->getPath();

        if (basename($scriptName) === $baseScriptFileName) {
            $basePath = $scriptName;
        } else {
            $self = Server::get('PHP_SELF') ?? '';

            if ($self !== '' && basename($self) === $baseScriptFileName) {
                $basePath = $self;
            } else {
                // try to match a part of SCRIPT_FILENAME in PHP_SELF
                $basePath = '';
                $segments = explode('/', $scriptFileName);

                for ($i = count($segments) - 1; $i >= 0; --$i) {
                    $basePath = "/{$segments[$i]}{$basePath}";

                    if (!strpos($self, $basePath)) {
                        break; // part not matched or matched at the beginning
                    }
                }
            }
        }

        // attempt to match base path in the request path
        if ($basePath !== '') {
            $prefix = self::getUrlencodedPrefix($requestPath, $basePath);

            if ($prefix !== null) {
                // full base path matches
                return $prefix;
            }

            $prefix = self::getUrlencodedPrefix($requestPath, rtrim(dirname($basePath), '/\\'));

            if ($prefix !== null) {
                // directory part of base path matches
                return rtrim($prefix, '/\\');
            }
        }

        // attempt to match basename in the request path
        $baseName = basename($basePath);

        if ($baseName === '' || strpos(rawurldecode($requestPath), $baseName) === false) {
            // no match at all

            return '';
        }

        // remove script filename if using mod_rewrite or ISAPI_Rewrite
        if (strlen($requestPath) >= strlen($basePath) && ($basePathPos = strpos($requestPath, $basePath))) {
            $basePath = substr($requestPath, 0, $basePathPos + strlen($basePath));
        }

        return rtrim($basePath, '/\\');
    }

    private static function getUrlencodedPrefix(string $haystack, string $prefix): ?string
    {
        $prefixLength = strlen($prefix);

        if (
            strncmp(rawurldecode($haystack), $prefix, $prefixLength) === 0
            && preg_match(sprintf('{(?:%%[[:xdigit:]]{2}|.){%d}}A', $prefixLength), $haystack, $match)
        ) {
            return $match[0];
        }

        return null;
    }

    private static function determinePathInfo(): string
    {
        return (string) substr(static::getUrl()->getPath(), strlen(static::getBasePath()));
    }

    private static function normalizeAndFilterClientIps(array $clientIps)
    {
        $filteredClientIps = [];

        foreach ($clientIps as $clientIp) {
            $clientIp = self::normalizeClientIp($clientIp);

            if (
                filter_var($clientIp, FILTER_VALIDATE_IP) !== false
                && (
                    !self::$trustedProxies
                    || !self::$trustedProxies->isIpFromTrustedProxy($clientIp)
                )
            ) {
                $filteredClientIps[] = $clientIp;
            }
        }

        return $filteredClientIps;
    }

    private static function normalizeClientIp(string $clientIp): string
    {
        // remove port (non-standard)
        if (
            ($lastColonPos = strrpos($clientIp, ':')) !== false
            && (
                strpos($clientIp, ':') === $lastColonPos // IPv4
                || substr($clientIp, $lastColonPos - 1, 1) === ']' // IPv6 requires brackets
            )
        ) {
            $clientIp = substr($clientIp, 0, $lastColonPos);
        }

        // remove square braces
        if ($clientIp !== '' && $clientIp[0] === '[' && $clientIp[-1] === ']') {
            $clientIp = substr($clientIp, 1, -1);
        }

        return $clientIp;
    }

    /**
     * @return string[]
     */
    private static function getForwardedPropValues(string $prop, ?callable $filter = null): array
    {
        if (!self::$trustedProxies) {
            return []; // @codeCoverageIgnore
        }

        return self::$trustedProxies->getForwardedPropValues(
            static::getHeaders(),
            self::$trustedProxies->isTrustedProxyHeader(TrustedProxies::HEADER_FORWARDED)
                ? self::getForwardedHeaderGroups()
                : null,
            $prop,
            $filter
        );
    }

    /**
     * @return array[]
     */
    private static function getForwardedHeaderGroups(): array
    {
        if (isset(self::$cache['forwarded_header_groups'])) {
            return self::$cache['forwarded_header_groups'];
        }

        $headers = static::getHeaders();

        if (isset($headers['forwarded'])) {
            $forwardedHeaderGroups = Parser::parseForwardedHeader($headers['forwarded']) ?? [];
        } else {
            $forwardedHeaderGroups = [];
        }

        return self::$cache['forwarded_header_groups'] = $forwardedHeaderGroups;
    }
}
