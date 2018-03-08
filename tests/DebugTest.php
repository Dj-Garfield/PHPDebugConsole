<?php

/**
 * PHPUnit tests for Debug class
 */
class DebugTest extends DebugTestFramework
{

    /**
     * for given $var, check if it's abstraction type is of $type
     *
     * @param array  $var  abstracted $var
     * @param string $type array, object, or resource
     *
     * @return boolean
     */
    protected function checkAbstractionType($var, $type)
    {
        $return = false;
        /*
        if ($type == 'array') {
            $return = $var['debug'] === \bdk\Debug\Abstracter::ABSTRACTION
                && $var['type'] === 'array'
                && isset($var['values'])
                && isset($var['isRecursion']);
        } else
        */
        if ($type == 'object') {
            $keys = array('collectMethods','viaDebugInfo','isExcluded','isRecursion',
                    'extends','implements','constants','properties','methods','scopeClass','stringified');
            $keysMissing = array_diff($keys, array_keys($var));
            $return = $var['debug'] === \bdk\Debug\Abstracter::ABSTRACTION
                && $var['type'] === 'object'
                && $var['className'] === 'stdClass'
                && count($keysMissing) == 0;
        } elseif ($type == 'resource') {
            $return = $var['debug'] === \bdk\Debug\Abstracter::ABSTRACTION
                && $var['type'] === 'resource'
                && isset($var['value']);
        }
        return $return;
    }

    /**
     * Test that errorHandler onShutdown occurs before internal onShutdown
     *
     * @return void
     */
    public function testShutDownSubscribers()
    {
        $subscribers = $this->debug->eventManager->getSubscribers('php.shutdown');
        $this->assertSame($this->debug->errorHandler, $subscribers[0][0]);
        $this->assertSame('onShutdown', $subscribers[0][1]);
        $this->assertSame($this->debug->internal, $subscribers[1][0]);
        $this->assertSame('onShutdown', $subscribers[1][1]);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testAssert()
    {
        $this->debug->assert(false, 'this is false');
        $this->debug->assert(true, 'this is true... not logged');
        $log = $this->debug->getData('log');
        $this->assertCount(1, $log);
        $this->assertSame(array('assert',array('this is false'), array()), $log[0]);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testCount()
    {
        $this->debug->count('count test');
        for ($i=0; $i<3; $i++) {
            $this->debug->count();
            $this->debug->count('count test');
            \bdk\Debug::_count();
        }
        $log = $this->debug->getData('log');
        $log = array_slice($log, -3);
        $this->assertSame(array(
            array('count', array('count', 3), array()),
            array('count', array('count test', 4), array()),
            array('count', array('count', 3), array()),
        ), $log);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testError()
    {
        $resource = fopen(__FILE__, 'r');
        \bdk\Debug::_error('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->getData('log');
        $logEntry = $log[0];
        $this->assertSame('error', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1][0]);
        // check array abstraction
        // $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[1][2], 'object');
        $isResource = $this->checkAbstractionType($logEntry[1][3], 'resource');
        $this->assertSame(array(
            'file' => __FILE__,
            'line' => __LINE__ - 12,
            // 'debug' => \bdk\Debug::META,
        ), $logEntry[2]);

        // $this->assertTrue($isArray, 'is Array');
        $this->assertTrue($isObject, 'is Object');
        $this->assertTrue($isResource, 'is Resource');
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGetCfg()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroup()
    {
        $this->debug->group('a', 'b', 'c');
        $logEntry = $this->debug->getData('log/0');
        $this->assertSame(array('group',array('a','b','c'), array()), $logEntry);
        $depth = $this->debug->getData('groupDepth');
        $this->assertSame(1, $depth);
        $this->debug->group($this->debug->meta('hideIfEmpty'));
        $output = $this->debug->output();
        $outputExpect = <<<EOD
<div class="group-header expanded"><span class="group-label">a(</span><span class="t_string">b</span>, <span class="t_string">c</span><span class="group-label">)</span></div>
<div class="m_group">
</div>
EOD;
        $this->assertContains($outputExpect, $output);

        $test = new \bdk\DebugTest\Test();
        $testBase = new \bdk\DebugTest\TestBase();

        /*
            Test default label
        */

        \bdk\Debug::_group();
        $this->assertSame(array(
            'group',
            array(
                __CLASS__.'->'.__FUNCTION__
            ),
            array(
                'isMethodName' => true,
            ),
        ), $this->debug->getData('log/0'));

        $this->debug->setData('log', array());
        $testBase->testBasePublic();
        $this->assertSame(array(
            'group',
            array(
                'bdk\DebugTest\TestBase->testBasePublic'
            ),
            array(
                'isMethodName' => true,
            ),
        ), $this->debug->getData('log/0'));

        $this->debug->setData('log', array());
        $test->testBasePublic();
        $this->assertSame(array(
            'group',
            array(
                'bdk\DebugTest\Test->testBasePublic'
            ),
            array(
                'isMethodName' => true,
            ),
        ), $this->debug->getData('log/0'));

        // yes, we call Test... but static method is defined in TestBase
        // .... PHP
        $this->debug->setData('log', array());
        \bdk\DebugTest\Test::testBaseStatic();
        $this->assertSame(array(
            'group',
            array(
                'bdk\DebugTest\TestBase::testBaseStatic'
            ),
            array(
                'isMethodName' => true,
            ),
        ), $this->debug->getData('log/0'));

        // even if called with an arrow
        $this->debug->setData('log', array());
        $test->testBaseStatic();
        $this->assertSame(array(
            'group',
            array(
                'bdk\DebugTest\TestBase::testBaseStatic'
            ),
            array(
                'isMethodName' => true,
            ),
        ), $this->debug->getData('log/0'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupCollapsed()
    {
        $this->debug->groupCollapsed('a', 'b', 'c');
        $log = $this->debug->getData('log');
        $this->assertSame(array('groupCollapsed', array('a','b','c'), array()), $log[0]);
        $depth = $this->debug->getData('groupDepth');
        $this->assertSame(1, $depth);
        $this->debug->groupCollapsed($this->debug->meta('hideIfEmpty'));
        $output = $this->debug->output();
        $outputExpect = <<<EOD
<div class="group-header collapsed"><span class="group-label">a(</span><span class="t_string">b</span>, <span class="t_string">c</span><span class="group-label">)</span></div>
<div class="m_group">
</div>
EOD;
        $this->assertContains($outputExpect, $output);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupUncollapse()
    {
        $this->debug->groupCollapsed('level1 (test)');
        $this->debug->groupCollapsed('level2');
        $this->debug->log('left collapsed');
        $this->debug->groupEnd('level2');
        $this->debug->groupCollapsed('level2 (test)');
        $this->debug->groupUncollapse();
        $log = $this->debug->getData('log');
        $this->assertSame('group', $log[0][0]);
        $this->assertSame('groupCollapsed', $log[1][0]);
        $this->assertSame('group', $log[4][0]);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testGroupEnd()
    {
        $this->debug->group('a', 'b', 'c');
        $this->debug->groupEnd();
        $depth = $this->debug->getData('groupDepth');
        $this->assertSame(0, $depth);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testInfo()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->info('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->getData('log');
        $logEntry = $log[0];
        $this->assertSame('info', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1][0]);
        // check array abstraction
        // $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[1][2], 'object');
        $isResource = $this->checkAbstractionType($logEntry[1][3], 'resource');
        // $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testLog()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->log('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->getData('log');
        $logEntry = $log[0];
        $this->assertSame('log', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1][0]);
        // check array abstraction
        // $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[1][2], 'object');
        $isResource = $this->checkAbstractionType($logEntry[1][3], 'resource');
        // $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testOutput()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetCfg()
    {
    }

    /**
     * Test
     *
     * @return void
     */
    public function testSetErrorCaller()
    {
        $this->setErrorCallerHelper();
        $errorCaller = $this->debug->errorHandler->get('errorCaller');
        $this->assertSame(array(
            'file' => __FILE__,
            'line' => __LINE__ - 4,
            'depth' => 0
        ), $errorCaller);

        // this will use maximum debug_backtrace depth
        call_user_func(array($this, 'setErrorCallerHelper'), true);
        $errorCaller = $this->debug->errorHandler->get('errorCaller');
        $this->assertSame(array(
            'file' => __FILE__,
            'line' => __LINE__ - 4,
            'depth' => 0
        ), $errorCaller);
    }

    private function setErrorCallerHelper($static = false)
    {
        if ($static) {
            \bdk\Debug::_setErrorCaller();
        } else {
            $this->debug->setErrorCaller();
        }
    }

    public function testSubstitution()
    {
        $location = 'http://localhost/?foo=bar&jim=slim';
        $this->debug->log('%cLocation:%c <a href="%s">%s</a>', 'font-weight:bold;', '', $location, $location);
        $output = $this->debug->output();
        $outputExpect = '<div class="m_log"><span class="t_string no-pseudo"><span style="font-weight:bold;">Location:</span><span> <a href="http://localhost/?foo=bar&amp;jim=slim">http://localhost/?foo=bar&amp;jim=slim</a></span></span></div>';
        $this->assertContains($outputExpect, $output);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTime()
    {
        $this->debug->time();
        $this->debug->time('some label');
        $this->assertInternalType('float', $this->debug->getData('timers/stack/0'));
        $this->assertInternalType('float', $this->debug->getData('timers/labels/some label/1'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTimeEnd()
    {
        $this->debug->time();
        $this->debug->time('my label');
        $this->debug->timeEnd();            // appends log
        // test stack is now empty
        $this->assertCount(0, $this->debug->getData('timers/stack'));
        $this->debug->timeEnd('my label');  // appends log
        $ret = $this->debug->timeEnd('my label', true);
        // $this->assertInternalType('float', $ret);
        $this->assertStringMatchesFormat('%f', $ret);
        // test last timeEnd didn't append log
        $this->assertCount(2, $this->debug->getData('log'));
        $timers = $this->debug->getData('timers');
        $this->assertInternalType('float', $timers['labels']['my label'][0]);
        $this->assertNull($timers['labels']['my label'][1]);
        $this->debug->timeEnd('my label', 'blah%labelblah%timeblah');
        $this->assertStringMatchesFormat('blahmy labelblah%fblah', $this->debug->getData('log/2/1/0'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTimeGet()
    {
        $this->debug->time();
        $this->debug->time('my label');
        $this->debug->timeGet();            // appends log
        // test stack is still 1
        $this->assertCount(1, $this->debug->getData('timers/stack'));
        $this->debug->timeGet('my label');  // appends log
        $ret = $this->debug->timeGet('my label', true);
        // $this->assertInternalType('float', $ret);
        $this->assertStringMatchesFormat('%f', $ret);
        // test last timeEnd didn't append log
        $this->assertCount(2, $this->debug->getData('log'));
        $timers = $this->debug->getData('timers');
        $this->assertSame(0, $timers['labels']['my label'][0]);
        // test not paused
        $this->assertNotNull($timers['labels']['my label'][1]);
        $this->debug->timeGet('my label', 'blah%labelblah%timeblah');
        $this->assertStringMatchesFormat('blahmy labelblah%fblah', $this->debug->getData('log/2/1/0'));
    }

    /**
     * Test
     *
     * @return void
     */
    public function testTrace()
    {
        $this->debug->trace();
        $trace = $this->debug->getData('log/0/1/0');
        $this->assertSame(__FILE__, $trace[0]['file']);
        $this->assertSame(__LINE__ - 3, $trace[0]['line']);
        $this->assertNotTrue(isset($trace[0]['function']));
        $this->assertSame(__CLASS__.'->'.__FUNCTION__, $trace[1]['function']);
    }

    /**
     * Test
     *
     * @return void
     */
    public function testWarn()
    {
        $resource = fopen(__FILE__, 'r');
        $this->debug->warn('a string', array(), new stdClass(), $resource);
        fclose($resource);
        $log = $this->debug->getData('log');
        $logEntry = $log[0];
        $this->assertSame('warn', $logEntry[0]);
        $this->assertSame('a string', $logEntry[1][0]);
        // check array abstraction
        // $isArray = $this->checkAbstractionType($logEntry[2], 'array');
        $isObject = $this->checkAbstractionType($logEntry[1][2], 'object');
        $isResource = $this->checkAbstractionType($logEntry[1][3], 'resource');
        // $this->assertTrue($isArray);
        $this->assertTrue($isObject);
        $this->assertTrue($isResource);
    }
}
