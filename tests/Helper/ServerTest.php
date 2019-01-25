<?php declare(strict_types=1);

namespace Kuria\RequestInfo\Helper;

use Kuria\DevMeta\Test;
use Kuria\RequestInfo\Exception\UndefinedServerValueException;

/**
 * @backupGlobals enabled
 */
class ServerTest extends Test
{
    function testShouldGetServerValue()
    {
        $this->setTestServerData();

        $this->assertSame('value', Server::get('STRING'));
        $this->assertSame('123', Server::get('INT'));
        $this->assertSame('', Server::get('EMPTY'));
        $this->assertSame('dummy', Server::get('lowercase'));
        $this->assertNull(Server::get('string'));
        $this->assertNull(Server::get('NONEXISTENT'));
    }

    function testShouldRequireServerValue()
    {
        $this->setTestServerData();

        $this->assertSame('value', Server::require('STRING'));

        $this->expectException(UndefinedServerValueException::class);
        $this->expectExceptionMessage('$_SERVER[NONEXISTENT] is not defined');

        Server::require('NONEXISTENT');
    }

    function testShouldCheckServerValue()
    {
        $this->setTestServerData();

        $this->assertTrue(Server::has('STRING'));
        $this->assertTrue(Server::has('INT'));
        $this->assertTrue(Server::has('EMPTY'));
        $this->assertTrue(Server::has('lowercase'));
        $this->assertFalse(Server::has('string'));
        $this->assertFalse(Server::has('NONEXISTENT'));
    }

    function testShouldCheckNonEmptyServerValue()
    {
        $this->setTestServerData();

        $this->assertTrue(Server::hasNotEmpty('STRING'));
        $this->assertTrue(Server::hasNotEmpty('INT'));
        $this->assertFalse(Server::hasNotEmpty('EMPTY'));
        $this->assertTrue(Server::hasNotEmpty('lowercase'));
        $this->assertFalse(Server::hasNotEmpty('string'));
        $this->assertFalse(Server::hasNotEmpty('NONEXISTENT'));
    }

    function testShouldGetHeaders()
    {
        $_SERVER = [
            'FOO' => 'bar',
            'BAZ' => 'qux',
            'CONTENT_QUUX' => 'quuz',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => 123,
            'CONTENT_MD5' => 'bb0071bbbe798d0791e1812aafa8e384',
            'HTTP_ACCEPT' => '*/*',
            'HTTP_USER_AGENT' => 'test/1.0',
            'HTTP_X_CUSTOM_HEADER' => 'foo',
        ];

        $this->assertSame(
            [
                'content-type' => 'application/json',
                'content-length' => 123,
                'content-md5' => 'bb0071bbbe798d0791e1812aafa8e384',
                'accept' => '*/*',
                'user-agent' => 'test/1.0',
                'x-custom-header' => 'foo',
            ],
            Server::getHeaders()
        );
    }

    private function setTestServerData(): void
    {
        $_SERVER = [
            'STRING' => 'value',
            'INT' => 123,
            'EMPTY' => '',
            'lowercase' => 'dummy',
        ];
    }
}
