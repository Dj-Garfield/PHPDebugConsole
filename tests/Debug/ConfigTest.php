<?php

namespace bdk\Test\Debug;

use bdk\Debug;
use bdk\HttpMessage\ServerRequest;

/**
 * PHPUnit tests for Debug class
 *
 * @covers \bdk\Debug\Config
 * @covers \bdk\Debug\ConfigEvents
 * @covers \bdk\Debug\Internal
 * @covers \bdk\Debug\InternalEvents
 * @covers \bdk\Debug\Plugin\AssertSettingTrait
 * @covers \bdk\Debug\Plugin\LogEnv
 * @covers \bdk\Debug\Plugin\Manager
 * @covers \bdk\Debug\Plugin\Redaction
 * @covers \bdk\Debug\Route\Stream
 * @covers \bdk\Debug\Utility\FileStreamWrapper
 */
class ConfigTest extends DebugTestFramework
{
    public function testAssertSettingShouldNotBe()
    {
        $reflectionMethod = new \ReflectionMethod($this->debug->pluginLogPhp, 'assertSetting');
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->invoke($this->debug->pluginLogPhp, array(
            'filter' => FILTER_VALIDATE_INT,
            'name' => 'testThing',
            'operator' => '!=',
            'valActual' => 666,
            'valCompare' => 666,
        ));
        $this->assertSame(array(
            'method' => 'assert',
            'args' => array(
                '%ctestThing%c: should not be 666',
                'font-family:monospace;',
                '',
            ),
            'meta' => array(
                'channel' => 'php',
            ),
        ), $this->helper->logEntryToArray($this->debug->data->get('log/__end__')));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGetCfg()
    {
        $configKeys = array(
            'abstracter',
            'debug',
            'errorHandler',
            'routeHtml',
            'routeStream',
        );
        $abstracterKeys = array(
            'brief',
            'caseAttributeCollect',
            'caseAttributeOutput',
            'caseCollect',
            'caseOutput',
            'constAttributeCollect',
            'constAttributeOutput',
            'constCollect',
            'constOutput',
            'fullyQualifyPhpDocType',
            'methodAttributeCollect',
            'methodAttributeOutput',
            'methodCache',
            'methodCollect',
            'methodDescOutput',
            'methodOutput',
            'objAttributeCollect',
            'objAttributeOutput',
            'objectsExclude',
            'objectSort',
            'objectsWhitelist',
            'paramAttributeCollect',
            'paramAttributeOutput',
            'phpDocCollect',
            'phpDocOutput',
            'propAttributeCollect',
            'propAttributeOutput',
            'stringMaxLen',
            'stringMinLen',
            'toStringOutput',
            'useDebugInfo',
        );
        $debugKeys = array(
            'collect',
            'key',
            'output',
            // 'arrayShowListKeys',
            'channels',
            'channelIcon',
            'channelName',
            'channelShow',
            'channelSort',
            'enableProfiling',
            'errorLogNormal',
            'errorMask',
            'emailFrom',
            'emailFunc',
            'emailLog',
            'emailTo',
            'exitCheck',
            'extensionsCheck',
            'headerMaxAll',
            'headerMaxPer',
            'logEnvInfo',
            'logRequestInfo',
            'logResponse',
            'logResponseMaxLen',
            'logRuntime',
            'logServerKeys',
            'onBootstrap',
            'onLog',
            'onOutput',
            'outputHeaders',
            'redactKeys',
            // 'redactReplace',
            'route',
            'routeNonHtml',
            'serviceProvider',
            'sessionName',
            'wampPublisher',
            'container',
        );
        \sort($debugKeys);

        $this->assertSame(true, $this->debug->getCfg('collect'));
        $this->assertSame(true, $this->debug->getCfg('debug.collect'));

        $this->assertSame('visibility', $this->debug->getCfg('objectSort'));
        $this->assertSame('visibility', $this->debug->getCfg('abstracter.objectSort'));
        $this->assertSame('visibility', $this->debug->getCfg('abstracter/objectSort'));

        $keysActual = \array_keys($this->debug->getCfg('debug'));
        \sort($keysActual);
        $this->assertSame($debugKeys, $keysActual);

        $keysActual = \array_keys($this->debug->getCfg('abstracter'));
        \sort($keysActual, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($abstracterKeys, $keysActual);

        $keysActual = \array_keys($this->debug->getCfg('abstracter/*'));
        \sort($keysActual, SORT_STRING | SORT_FLAG_CASE);
        $this->assertSame($abstracterKeys, $keysActual);

        $this->assertIsBool($this->debug->getCfg('output'));       // debug/output

        $this->assertSame($configKeys, \array_keys($this->debug->getCfg()));
        $this->assertSame($configKeys, \array_keys($this->debug->getCfg('*')));
        $keysActual = \array_keys($this->debug->getCfg('debug/*'));
        \sort($keysActual);
        $this->assertSame($debugKeys, $keysActual);
        $this->assertSame(false, $this->debug->getCfg('logRequestInfo/cookies'));

        $this->assertSame(null, $this->debug->getCfg('dumpNonExistent'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetCfg()
    {
        $this->assertSame(null, $this->debug->setCfg('foo', 'bar'));
        $this->assertSame('bar', $this->debug->setCfg('foo', 'baz'));
        $this->assertSame('baz', $this->debug->getCfg('foo'));
    }

    public function testOnCfgEmail()
    {
        /*
            'emailTo' is currently set to null for tests..
        */
        $this->assertNull($this->debug->getCfg('emailTo'));

        /*
            test setting to 'default'
        */
        $this->debug->setCfg('emailTo', 'default');
        $this->assertSame('testAdmin@test.com', $this->debug->getCfg('emailTo'));
        $this->assertSame('testAdmin@test.com', $this->debug->getCfg('errorHandler.emailer.emailTo'));

        /*
            updating the request obj will not update the default email!!
            this is intentional
        */
        $this->debug->setCfg('serviceProvider', array(
            'serverRequest' => new ServerRequest(
                'GET',
                null,
                array(
                    'SERVER_ADMIN' => 'bkfake-github@yahoo.com',
                )
            ),
        ));
        $this->assertSame('testAdmin@test.com', $this->debug->getCfg('emailTo'));
    }

    public function testSetDeepShortcut()
    {
        $format = 'm/d/Y h:i:s a';
        $this->debug->setCfg('dateTimeFmt', $format);
        $this->assertSame($format, $this->debug->getCfg('errorHandler/emailer/dateTimeFmt'));
        $this->assertSame($format, $this->debug->getCfg('dateTimeFmt'));
    }

    public function testOnCfgChannels()
    {
        $debug = new Debug(array(
            'channels' => array(
                'foo.bar' => array(
                    'channelIcon' => 'someIcon'
                ),
            ),
        ));
        $this->assertSame(array(
            'foo' => array(
                'channels' => array(
                    'bar' => array(
                        'channelIcon' => 'someIcon',
                    ),
                ),
            ),
        ), $debug->getCfg('channels'));
    }

    public function testOnCfgEnableProfiling()
    {
        $enableProfilingWas = $this->debug->setCfg('enableProfiling', false);
        $this->debug->setCfg('enableProfiling', true);
        $this->assertTrue($this->debug->getCfg('enableProfiling'));
        $this->debug->setCfg('enableProfiling', $enableProfilingWas);
    }

    public function testOnCfgKey()
    {
        $debug = new Debug(array(
            'key' => 'swordfish',
            'logResponse' => false,
            'serviceProvider' => array(
                'serverRequest' => (new ServerRequest(
                    'GET',
                    null,
                    array(
                        'REQUEST_METHOD' => 'GET',
                    )
                ))
                    ->withQueryParams(array(
                        'debug' => 'swordfish',
                    )),
            ),
        ));
        $this->assertTrue($debug->getCfg('collect'));
        $this->assertTrue($debug->getCfg('output'));
        $debug->setCfg('output', false);

        $debug = new Debug(array(
            'key' => 'swordfish',
            'logResponse' => false,
            'serviceProvider' => array(
                'serverRequest' => (new ServerRequest(
                    'GET',
                    null,
                    array(
                        'REQUEST_METHOD' => 'GET',
                    )
                ))
                    ->withCookieParams(array(
                        'debug' => 'swordfish',
                    )),
            ),
        ));
        $this->assertTrue($debug->getCfg('collect'));
        $this->assertTrue($debug->getCfg('output'));
        $debug->setCfg('output', false);
    }

    public function testOnCfgList()
    {
        $this->debug->setCfg('logEnvInfo', array('files'));
        $this->assertSame(array(
            'errorReporting' => false,
            'files' => true,
            'gitInfo' => false,
            'phpInfo' => false,
            'serverVals' => false,
            'session' => false,
        ), $this->debug->getCfg('logEnvInfo'));
    }

    public function testOnCfgLogResponse()
    {
        $this->debug->setCfg('logResponse', 'auto');
        $this->assertTrue($this->debug->getCfg('logResponse'));
        $this->debug->obEnd();
    }

    public function testOnCfgOnBootstrap()
    {
        $called = false;
        new Debug(array(
            'onBootstrap' => function () use (&$called) {
                $called = true;
            },
        ));
        $this->assertTrue($called);

        $called = false;
        $this->debug->setCfg('onBootstrap', function () use (&$called) {
            $called = true;
        });
        $this->assertTrue($called);
    }

    /**
     * @dataProvider providerOnCfgReplaceSubscriber
     */
    public function testOnCfgReplaceSubscriber($name, $event)
    {
        $closure1 = function () {
        };
        $this->debug->setCfg($name, $closure1);
        $closure2 = function () {
            echo 'you suck!!!' . "\n";
        };
        $this->debug->setCfg($name, $closure2);
        $closure1isSub = false;
        $closure2isSub = false;
        foreach ($this->debug->eventManager->getSubscribers($event) as $sub) {
            if ($sub === $closure1) {
                $closure1isSub = true;
            }
            if ($sub === $closure2) {
                $closure2isSub = true;
            }
        }
        $this->assertFalse($closure1isSub);
        $this->assertTrue($closure2isSub);

        $this->debug->setCfg($name, null);
    }

    /*
    public function testOnCfgOnMiddleware()
    {
        $this->debug->setCfg('onMiddleware', function () {
        });
        $this->assertCount(1, $this->debug->eventManager->getSubscribers(Debug::EVENT_MIDDLEWARE));
        $callable =  function () {};
        $this->debug->setCfg('onMiddleware', $callable);
        $this->assertSame(array($callable), $this->debug->eventManager->getSubscribers(Debug::EVENT_MIDDLEWARE));
    }
    */

    /**
     * Test config stores config values until class is instantiated
     *
     * @return void
     */
    public function testPending()
    {
        $debug = new Debug(array(
            'logResponse' => false,
        ));

        $this->assertTrue($debug->getCfg('methodCollect'));
        $debug->setCfg('methodCollect', false);
        $this->assertFalse($debug->getCfg('methodCollect'));
        $debug->abstracter;
        $this->assertFalse($debug->abstracter->getCfg('methodCollect'));

        // routeHtml should not yet be loaded
        $this->assertFalse($debug->getRoute('html', true));
        // getting filepathScript should load routeHtml
        $filepathScript = $debug->getCfg('filepathScript');
        $this->assertIsString($filepathScript);
        $this->assertTrue($debug->getRoute('html', true));
    }

    public function testNotInvokedOrPending()
    {
        $debug = new Debug(array(
            'logEnvInfo' => false,
        ));
        $this->assertSame('visibility', $debug->getCfg('abstracter.objectSort'));
    }

    public static function providerOnCfgReplaceSubscriber()
    {
        return array(
            'onLog' => array('onLog', Debug::EVENT_LOG),
            'onMiddleware' => array('onMiddleware', Debug::EVENT_MIDDLEWARE),
            'onOutput' => array('onOutput', Debug::EVENT_OUTPUT),
        );
    }
}
