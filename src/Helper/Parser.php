<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

abstract class Parser
{
    private const FORWARDED_HEADER_PROP_MAP = ['by' => true, 'for' => true, 'host' => true, 'proto' => true];

    /**
     * Parse contents of the "Forwarded" header
     *
     * Returns an array of property groups or NULL on failure.
     *
     * @return array[]|null
     */
    static function parseForwardedHeader(string $header): ?array
    {
        $header = rtrim($header, " \t");

        $validProps = ['by', 'for', 'host', 'proto'];

        $offset = 0;
        $length = strlen($header);

        $groups = [];
        $group = array_fill_keys($validProps, null);

        if ($length === 0) {
            // blank header
            return null;
        }

        while ($offset < $length) {
            // match a property
            if (
            !preg_match(
                '{\h*+(?P<prop>\w++)=(?:"(?P<qvalue>(?:[^"\\\\]|\\\\.)*+)"|(?P<value>[^",;\h]++))\h*(?P<sep>(?>[,;]|$))}AD',
                $header,
                $match,
                0,
                $offset
            )
            ) {
                // syntax error
                return null;
            }

            // detect duplicate property
            if (isset($group[$match['prop']])) {
                return null;
            }

            // set property value (if it is a valid property)
            if (isset(self::FORWARDED_HEADER_PROP_MAP[$match['prop']])) {
                if (isset($match['value']) && $match['value'] !== '') {
                    $group[$match['prop']] = $match['value'];
                } else {
                    $group[$match['prop']] = stripslashes($match['qvalue']);
                }
            }

            // update offset
            $offset += strlen($match[0]);

            // update group if needed
            if ($match['sep'] === ',') {
                // init new group on comma separator
                $groups[] = $group;
                $group = array_fill_keys($validProps, null);
            } elseif ($offset >= $length) {
                // push current group when end is reached
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Parse hostname and port from a string (host:port)
     *
     * - returns an array with the "host" and "port" keys
     * - if the string does not contain a port, then "port" will be NULL
     */
    static function parseHostAndPort(string $host): array
    {
        if (
            ($colonPos = strpos($host, ':')) !== false
            && ctype_digit($port = substr($host, $colonPos + 1))
        ) {
            return [
                'host' => substr($host, 0, $colonPos),
                'port' => (int) $port,
            ];
        }

        return [
            'host' => $host,
            'port' => null,
        ];
    }
}
