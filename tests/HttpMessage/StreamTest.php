<?php

namespace bdk\Test\HttpMessage;

use bdk\HttpMessage\Stream;
use bdk\Test\PolyFill\AssertionTrait;
use bdk\Test\PolyFill\ExpectExceptionTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \bdk\HttpMessage\AbstractStream
 * @covers \bdk\HttpMessage\Stream
 */
class StreamTest extends TestCase
{
    use AssertionTrait;
    use ExpectExceptionTrait;

    public function testConstruct()
    {
        $filePath = TEST_DIR . '/assets/logo.png';
        $fileMd5 = \md5(\file_get_contents($filePath));

        // test 1
        $resource = \fopen($filePath, 'r+');
        $stream = new Stream($resource);

        $this->assertTrue($stream instanceof Stream);
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertIsInt($stream->getSize());
        $this->assertIsBool($stream->eof());
        $this->assertIsInt($stream->tell());

        // close.
        $this->assertEquals($resource, $stream->detach());
        $this->assertNull($stream->getSize());
        $this->assertNull($stream->detach());

        // Test 2
        $resource = \fopen($filePath, 'c');
        $stream = new Stream($resource);
        $this->assertTrue($stream->isWritable());
        $this->assertFalse($stream->isReadable());

        // Test 3
        $resource = \fopen($filePath, 'r');
        $stream = new Stream($resource);
        $this->assertFalse($stream->isWritable());
        $this->assertTrue($stream->isReadable());

        // Test 4 (via file)
        $stream = new Stream($filePath);
        $this->assertFalse($stream->isWritable());
        $this->assertTrue($stream->isReadable());
        $this->assertSame(0, $stream->tell());
        $this->assertSame($fileMd5, \md5($stream->getContents()));
        $this->assertSame(\filesize($filePath), $stream->tell());
        $this->assertSame(\md5(''), \md5($stream->getContents()));

        // Test 5 (via string)
        $stream = new Stream('This is a test');
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isReadable());

        // Test 6 (new temp)
        $stream = new Stream();
        $this->assertTrue($stream->isWritable());
        $this->assertTrue($stream->isReadable());

        // Test 7 (new temp)
        $stream = new Stream('This is a test', array(
            'metadata' => array('pizza' => 'sausage'),
        ));
        $this->assertSame('sausage', $stream->getMetadata('pizza'));
    }

    public function testToString()
    {
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');

        $this->assertSame('Foo Bar', (string) $stream);

        $stream->close();
        $this->assertSame('', (string) $stream);
    }

    public function testGetSize()
    {
        $resource = \fopen(TEST_DIR . '/assets/logo.png', 'r+');
        $stream = new Stream($resource, array(
            'size' => 123,
        ));
        $this->assertSame(123, $stream->getSize());

        $resource = \fopen(TEST_DIR . '/assets/logo.png', 'r+');
        $stream = new Stream($resource);
        $this->assertSame(\filesize(TEST_DIR . '/assets/logo.png'), $stream->getSize());
        $stream->close();
    }

    public function testGetMetadata()
    {
        $resource = \fopen(TEST_DIR . '/assets/logo.png', 'r+');
        $stream = new Stream($resource);
        $expectedMeta = [
            'blocked' => true,
            'eof' => false,
            'mode' => 'r+',
            'seekable' => true,
            // 'stream_type' => 'STDIO',
            'timed_out' => false,
            'unread_bytes' => 0,
            'uri' => TEST_DIR . '/assets/logo.png',
            // 'wrapper_type' => 'plainfile',
        ];

        $meta = $stream->getMetadata();
        // stream_type and wrapper_type may differ due to bdk\Debug\Utility\FileStreamWrapper
        $meta = \array_intersect_key($meta, $expectedMeta);
        \ksort($meta);
        $this->assertEquals($expectedMeta['mode'], $meta['mode']);
        $this->assertEquals('r+', $stream->getMetadata('mode'));
        $this->assertEquals($expectedMeta, $meta);
        $stream->close();
        $this->assertEquals(array(), $stream->getMetadata());
    }

    public function testSeekAndRewind()
    {
        $resource = \fopen(TEST_DIR . '/assets/logo.png', 'r+');
        $stream = new Stream($resource);
        $stream->seek(10);
        $this->assertSame(10, $stream->tell());
        $stream->rewind();
        $this->assertSame(0, $stream->tell());
        $stream->close();
    }

    public function testReadAndWrite()
    {
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');
        $stream->rewind();
        $this->assertSame('Foo ', $stream->read(4));
        $this->assertSame('', $stream->read(0));
        $stream->close();
    }

    /*
        Exceptions
    */

    public function testExceptionConstruct()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectExceptionMessage('Expected resource, filename, or string. stdClass provided');
        // Exception => Stream should be a resource, but string provided.
        new Stream(new \stdClass());
    }

    public function testExceptionEof()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->close();
        $stream->eof();
    }

    public function testExceptionGetContentsClosed()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');
        $stream->rewind();
        $stream->close();
        // Exception => Stream does not exist.
        $stream->getContents();
    }

    public function testExceptionGetContentsNotReadable()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Unable to read from stream');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');
        $stream->rewind();

        \bdk\Test\Debug\Helper::setPrivateProp($stream, 'readable', false);
        // Exception => Unable to read stream contents.
        $stream->getContents();
    }

    public function testExceptionReadClosed()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');
        $stream->close();
        $stream->read(2);
    }

    public function testExceptionReadNot()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Unable to read from non-readable stream');
        $stream = new Stream(\fopen(TEST_DIR . '/assets/logo.png', 'a'));
        $stream->read(100);
    }

    public function testExceptionReadNegative()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Length parameter cannot be negative');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');
        $stream->read(-10);
    }

    /*
    public function testExceptionReadFail()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Unable to read from stream');
        $fileSource = TEST_DIR . '/assets/logo.png';
        $fileCopy = TEST_DIR . '/../tmp/logo_clone.png';
        \copy($fileSource, $fileCopy);
        $resource = \fopen($fileCopy, 'r+');
        $stream = new Stream($resource);
        \unlink($fileCopy);
        $foo = $stream->read(100);
        \bdk\Test\Debug\Helper::stderr('foo', $foo);
    }
    */

    public function testExceptionSeekStreamDoesNotExist()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->close();
        // Exception => Stream does not exist.
        $stream->seek(10);
    }

    public function testExceptionSeekNotSeekable()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is not seekable');
        $stream = new Stream(\fopen('php://temp', 'r'));
        \bdk\Test\Debug\Helper::setPrivateProp($stream, 'seekable', false);
        $stream->seek(10);
    }

    public function testExceptionSeekFail()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Unable to seek to stream position 10 with whence 0');
        $stream = new Stream(\fopen('php://temp', 'r'));
        $stream->seek(10);
    }

    public function testExceptionSetResourceFileError()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('The file some/readonly/file cannot be opened.');

        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->write('Foo Bar');

        $reflectionMethod = new ReflectionMethod($stream, 'setResourceFile');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($stream, 'some/readonly/file');
    }

    public function testExceptionTellClosed()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->close();
        $stream->tell();
    }

    /*
    public function testTellError()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('');
        $fileSource = TEST_DIR . '/assets/logo.png';
        $fileCopy = TEST_DIR . '/../tmp/logo_clone.png';
        \copy($fileSource, $fileCopy);
        $resource = \fopen($fileCopy, 'r+');
        $stream = new Stream($resource);
        \fread($resource, 100);
        \unlink($fileCopy);
        $stream->tell();
    }
    */

    /*
    public function testExceptionToStringFail()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Unable to read from stream');
        $fileSource = TEST_DIR . '/assets/logo.png';
        $fileCopy = TEST_DIR . '/../tmp/logo_clone.png';
        \copy($fileSource, $fileCopy);
        $resource = \fopen($fileCopy, 'a');
        $stream = new Stream($resource);
        \unlink($fileCopy);
        $foo = (string) $stream;
        \bdk\Test\Debug\Helper::stderr('foo', $foo);
    }
    */

    public function testExceptionWriteClosed()
    {
        $this->expectException('RuntimeException');
        $this->expectExceptionMessage('Stream is detached');
        $stream = new Stream(\fopen('php://temp', 'r+'));
        $stream->close();
        $stream->write('Foo Bar');
    }
}