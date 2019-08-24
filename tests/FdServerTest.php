<?php

namespace React\Tests\Socket;

use Clue\React\Block;
use React\EventLoop\Loop;
use React\Promise\Promise;
use React\Socket\FdServer;

class FdServerTest extends TestCase
{
    public function testCtorAddsResourceToLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        new FdServer($fd, $loop);
    }

    public function testCtorThrowsForInvalidFd()
    {
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException('InvalidArgumentException');
        new FdServer(-1, $loop);
    }

    public function testCtorThrowsForUnknownFd()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);
        fclose($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_EBADF) : 'Bad file descriptor'),
            defined('SOCKET_EBADF') ? SOCKET_EBADF : 9
        );
        new FdServer($fd, $loop);
    }

    public function testCtorThrowsIfFdIsAFileAndNotASocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $tmpfile = tmpfile();
        $fd = $this->getFdFromResource($tmpfile);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_ENOTSOCK) : 'Not a socket'),
            defined('SOCKET_ENOTSOCK') ? SOCKET_ENOTSOCK : 88
        );
        new FdServer($fd, $loop);
    }

    public function testCtorThrowsIfFdIsAConnectedSocketInsteadOfServerSocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $client = stream_socket_client('tcp://' . stream_socket_get_name($socket, false));

        $fd = $this->getFdFromResource($client);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->never())->method('addReadStream');

        $this->setExpectedException(
            'RuntimeException',
            'Failed to listen on FD ' . $fd . ': ' . (function_exists('socket_strerror') ? socket_strerror(SOCKET_EISCONN) : 'Socket is connected'),
            defined('SOCKET_EISCONN') ? SOCKET_EISCONN : 106
        );
        new FdServer($fd, $loop);
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForIpv4Socket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $this->assertEquals('tcp://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForIpv6Socket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = @stream_socket_server('[::1]:0');
        if ($socket === false) {
            $this->markTestSkipped('Listening on IPv6 not supported');
        }

        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $port = preg_replace('/.*:/', '', stream_socket_get_name($socket, false));
        $this->assertEquals('tcp://[::1]:' . $port, $server->getAddress());
    }

    public function testGetAddressReturnsSameAddressAsOriginalSocketForUnixDomainSocket()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = @stream_socket_server($this->getRandomSocketUri());
        if ($socket === false) {
            $this->markTestSkipped('Listening on Unix domain socket (UDS) not supported');
        }

        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);

        $this->assertEquals('unix://' . stream_socket_get_name($socket, false), $server->getAddress());
    }

    public function testGetAddressReturnsNullAfterClose()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = new FdServer($fd, $loop);
        $server->close();

        $this->assertNull($server->getAddress());
    }

    public function testCloseRemovesResourceFromLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->close();
    }

    public function testCloseTwiceRemovesResourceFromLoopOnce()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->close();
        $server->close();
    }

    public function testResumeWithoutPauseIsNoOp()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream');

        $server = new FdServer($fd, $loop);
        $server->resume();
    }

    public function testPauseRemovesResourceFromLoop()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->pause();
    }

    public function testPauseAfterPauseIsNoOp()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('removeReadStream');

        $server = new FdServer($fd, $loop);
        $server->pause();
        $server->pause();
    }

    public function testServerEmitsConnectionEventForNewConnection()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $client = stream_socket_client('tcp://' . stream_socket_get_name($socket, false));

        $server = new FdServer($fd, Loop::get());
        $promise = new Promise(function ($resolve) use ($server) {
            $server->on('connection', $resolve);
        });

        $connection = Block\await($promise, Loop::get(), 1.0);

        $this->assertInstanceOf('React\Socket\ConnectionInterface', $connection);

        fclose($client);
    }

    public function testEmitsErrorWhenAcceptListenerFails()
    {
        if (!is_dir('/dev/fd') || defined('HHVM_VERSION')) {
            $this->markTestSkipped('Not supported on your platform');
        }

        $listener = null;
        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();
        $loop->expects($this->once())->method('addReadStream')->with($this->anything(), $this->callback(function ($cb) use (&$listener) {
            $listener = $cb;
            return true;
        }));

        $socket = stream_socket_server('127.0.0.1:0');
        $fd = $this->getFdFromResource($socket);

        $server = new FdServer($fd, $loop);

        $exception = null;
        $server->on('error', function ($e) use (&$exception) {
            $exception = $e;
        });

        $this->assertNotNull($listener);
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        $time = microtime(true);
        $listener($socket);
        $time = microtime(true) - $time;

        $this->assertLessThan(1, $time);

        $this->assertInstanceOf('RuntimeException', $exception);
        assert($exception instanceof \RuntimeException);
        $this->assertStringStartsWith('Unable to accept new connection: ', $exception->getMessage());

        return $exception;
    }

    /**
     * @param \RuntimeException $e
     * @requires extension sockets
     * @depends testEmitsErrorWhenAcceptListenerFails
     */
    public function testEmitsTimeoutErrorWhenAcceptListenerFails(\RuntimeException $exception)
    {
        $this->assertEquals('Unable to accept new connection: ' . socket_strerror(SOCKET_ETIMEDOUT), $exception->getMessage());
        $this->assertEquals(SOCKET_ETIMEDOUT, $exception->getCode());
    }

    /**
     * @param resource $resource
     * @return int
     * @throws \UnexpectedValueException
     * @throws \BadMethodCallException
     * @throws \UnderflowException
     * @copyright Copyright (c) 2018 Christian Lück, taken from https://github.com/clue/fd with permission
     */
    private function getFdFromResource($resource)
    {
        $stat = @fstat($resource);
        if (!isset($stat['ino']) || $stat['ino'] === 0) {
            throw new \UnexpectedValueException('Could not access inode of given resource (unsupported type or platform)');
        }

        $dir = @scandir('/dev/fd');
        if ($dir === false) {
            throw new \BadMethodCallException('Not supported on your platform because /dev/fd is not readable');
        }

        $ino = (int) $stat['ino'];
        foreach ($dir as $file) {
            $stat = @stat('/dev/fd/' . $file);
            if (isset($stat['ino']) && $stat['ino'] === $ino) {
                return (int) $file;
            }
        }

        throw new \UnderflowException('Could not locate file descriptor for this resource');
    }

    private function getRandomSocketUri()
    {
        return "unix://" . sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid(rand(), true) . '.sock';
    }
}
