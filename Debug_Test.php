<?php

require_once dirname(__FILE__).'/../Debug.php';

/**
 * test
 */
class Test
{
    public $prop = 'val';
}

/**
 * PHPUnit tests for Debug class
 */
class DebugTests extends PHPUnit_Framework_TestCase
{

    /**
     * setUp is executed before each test
     *
     * @return void
     */
    public function setUp()
    {
        $this->debug = new Debug(array(
            'collect' => true,
            'output' => true,
            'outputCss' => false,
        ));
    }

    /**
     * tearDown is executed after each test
     *
     * @return void
     */
    public function tearDown()
    {
        unset($GLOBALS['debugClassData']);
    }

    /**
     * @return void
     */
    public function testDereferenceBasic()
    {
        $src = 'success';
        $ref = &$src;
        $this->debug->log('ref', $ref);
        $src = 'fail';
        //
        $output = $this->debug->output();
        $this->assertContains('success', $output);
    }

    /**
     * @return void
     */
    public function testDereferenceArray()
    {
        $test_val = 'success';
        $test_a = array(
            'ref' => &$test_val,
        );
        $this->debug->log('test_a', $test_a);
        $test_val = 'fail';
        //
        $output = $this->debug->output();
        $this->assertContains('success', $output);
    }

    /**
     * @return void
     */
    public function testRecursiveArray()
    {
        $test_a = array( 'foo' => 'bar' );
        $test_a['val'] = &$test_a;
        $this->debug->log('test_a', $test_a);
        //
        $output = $this->debug->output();
        $this->assertContains('t_recursion', $output);
    }

    /**
     * @return void
     */
    public function testRecursiveArray2()
    {
        /**
         * $test_a is a circular reference
         * $test_b references $test_a
         */
        $test_a = array();
        $test_a[] = &$test_a;
        $this->debug->log('test_a', $test_a);
        $test_b = array('foo', &$test_a, 'bar');
        $this->debug->log('test_b', $test_b);
        //
        $output = $this->debug->output();
        $xml = new DomDocument;
        $xml->loadXML($output);
        $this->assertSelectCount('.t_recursion', 2, $xml, 'Does not contain two recursion types');
    }

    /**
     * @return void
     *
     * v 1.0 = fatal error
     */
    public function testDereferenceObject()
    {
        $test_val = 'success A';
        $test_o = new Test();
        $test_o->prop = &$test_val;
        $this->debug->log('test_o', $test_o);
        $test_val = 'success B';
        $this->debug->log('test_o', $test_o);
        $test_val = 'fail';
        //
        $output = $this->debug->output();
        $this->assertContains('success A', $output);
        $this->assertContains('success B', $output);
        $this->assertNotContains('fail', $output);
        $this->assertSame('fail', $test_o->prop);   // prop should be 'fail' at this poing
    }

    /**
     * @return void
     *
     * v 1.0 = fatal error
     */
    public function testRecursiveObjectProp1()
    {
        $test = new Test();
        $test->prop = array();
        $test->prop[] = &$test->prop;
        $this->debug->log('test', $test);
        //
        $output = $this->debug->output();
        $xml = new DomDocument;
        $xml->loadXML($output);
        $select = '.log
            > .t_object > .t_object-inner
            > .t_array > .t_array-inner > .t_key_value
            > .t_array > .t_array-inner > .t_key_value
            > .t_array > .t_recursion';
        $this->assertSelectCount($select, 1, $xml);
    }

    /**
     * @return void
     */
    public function testRecursiveObjectProp2()
    {
        $test = new Test();
        $test->prop = &$test;
        $this->debug->log('test', $test);
        //
        $output = $this->debug->output();
        $xml = new DomDocument;
        $xml->loadXML($output);
        $select = '.log
            > .t_object > .t_object-inner
            > .t_array > .t_array-inner > .t_key_value
            > .t_object > .t_recursion';
        $this->assertSelectCount($select, 1, $xml);
    }

    /**
     * @return void
     */
    public function testRecursiveObjectProp3()
    {
        $test = new Test();
        $test->prop = array( &$test );
        $this->debug->log('test', $test);
        //
        $output = $this->debug->output();
        $xml = new DomDocument;
        $xml->loadXML($output);
        $select = '.log
            > .t_object > .t_object-inner
            > .t_array > .t_array-inner > .t_key_value
            > .t_array > .t_array-inner > .t_key_value
            > .t_object > .t_recursion';
        $this->assertSelectCount($select, 1, $xml);
    }

    public function testTypeResource()
    {
        $fh = fopen(__FILE__, 'r');
        $this->debug->log('resource', $fh);
        fclose($fh);
        $a = array(
            'resource' => fopen(__FILE__, 'r'),
        );
        $this->debug->log('array with resource', $a);
        fclose($a['resource']);
        //
        $output = $this->debug->output();
        //@todo assert
    }

    /**
     * @return void
     */
    public function testCrossRefObjects()
    {
        $test = new Test();
        $test_oa = new Test();
        $test_ob = new Test();
        $test_oa->prop = 'this is object a';
        $test_ob->prop = 'this is object b';
        $test_oa->ob = $test_ob;
        $test_ob->oa = $test_oa;
        $this->debug->log('test_oa', $test_oa);
        //
        $output = $this->debug->output();
        $xml = new DomDocument;
        $xml->loadXML($output);
        $select = '.log
            > .t_object > .t_object-inner
            > .t_array > .t_array-inner > .t_key_value
            > .t_object > .t_object-inner
            > .t_array > .t_array-inner > .t_key_value
            > .t_object > .t_recursion';
        $this->assertSelectCount($select, 1, $xml);
    }

}