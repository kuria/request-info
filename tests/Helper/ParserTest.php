<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

use Kuria\DevMeta\Test;

class ParserTest extends Test
{
    /**
     * @dataProvider provideForwardedHeaders
     */
    function testShouldParseForwardedHeader(string $header, ?array $expectedResult)
    {
        $this->assertSame($expectedResult, Parser::parseForwardedHeader($header));
    }

    function provideForwardedHeaders()
    {
        return [
            // header, expectedResult
            'empty' => [
                '',
                null,
            ],

            'whitespace only' => [
                '   ',
                null,
            ],

            'single property' => [
                'for=foo',
                [
                    ['by' => null, 'for' => 'foo', 'host' => null, 'proto' => null],
                ],
            ],

            'multiple properties' => [
                'by=foo; for=" bar "; host=baz; proto=https',
                [
                    ['by' => 'foo', 'for' => ' bar ', 'host' => 'baz', 'proto' => 'https'],
                ],
            ],

            'multiple groups' => [
                'by=foo; for=bar; host=baz, by=qux; for=quuz',
                [
                    ['by' => 'foo', 'for' => 'bar', 'host' => 'baz', 'proto' => null],
                    ['by' => 'qux', 'for' => 'quuz', 'host' => null, 'proto' => null],
                ],
            ],

            'whitespace handling' => [
                "  \t  for=foo   ;  host=bar  ,   host=baz",
                [
                    ['by' => null, 'for' => 'foo', 'host' => 'bar', 'proto' => null],
                    ['by' => null, 'for' => null, 'host' => 'baz', 'proto' => null],
                ],
            ],

            'bad whitespace' => [
                "\nfor=foo\r",
                null,
            ],

            'bad whitespace between groups' => [
                "for=foo; host=bar,\nfor=baz; host=qux",
                null,
            ],

            'bad param whitespace' => [
                "for = foo",
                null,
            ],

            'bad param whitespace 2' => [
                'for=foo, for = bar',
                null,
            ],

            'bad comma separator' => [
                "for=foo,,for=bar",
                null,
            ],

            'bad semicolon separator' => [
                "by=foo;;for=bar",
                null,
            ],

            'duplicate property' => [
                'by=foo; for=bar; by=baz',
                null,
            ],

            'duplicate property 2' => [
                'by=foo; for=bar, by=baz; by=qux',
                null,
            ],

            'unknown property' => [
                'for=foo; dummy=bar',
                [
                    ['by' => null, 'for' => 'foo', 'host' => null, 'proto' => null],
                ],
            ],

            'unknown property 2' => [
                'for=foo; proto=bar, dummy=baz; host=qux',
                [
                    ['by' => null, 'for' => 'foo', 'host' => null, 'proto' => 'bar'],
                    ['by' => null, 'for' => null, 'host' => 'qux', 'proto' => null],
                ],
            ],

            'empty group' => [
                ',',
                null,
            ],

            'empty groups' => [
                ' , , ',
                null,
            ],

            'quoted value' => [
                'for=" foo, bar; \\"baz\\" \\qux quuz \\""',
                [
                    ['by' => null, 'for' => ' foo, bar; "baz" qux quuz "', 'host' => null, 'proto' => null],
                ],
            ],

            'quoted values' => [
                "by=\" foo \"  ; for=\"bar\t\r\nbaz\", by=\"\"; for=\"qux\"",
                [
                    ['by' => ' foo ', 'for' => "bar\t\r\nbaz", 'host' => null, 'proto' => null],
                    ['by' => '', 'for' => 'qux', 'host' => null, 'proto' => null],
                ],
            ],

            'unterminated quoted value' => [
                'by="foo bar',
                null,
            ],

            'unterminated quoted value 2' => [
                'by=foo, by="foo bar',
                null,
            ],
        ];
    }
}
