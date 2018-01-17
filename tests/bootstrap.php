<?php

// backward compatibility
if (!class_exists('\PHPUnit\Framework\TestCase') &&
    class_exists('\PHPUnit_Framework_TestCase')
) {
    class_alias('\PHPUnit_Framework_Exception', '\PHPUnit\Framework\Exception');
    class_alias('\PHPUnit_Framework_TestCase', '\PHPUnit\Framework\TestCase');
    class_alias('\PHPUnit_Framework_TestSuite', '\PHPUnit\Framework\TestSuite');
}

require __DIR__.'/../src/Debug/Debug.php';
require __DIR__.'/DOMTest/DOMTestCase.php';
require __DIR__.'/DOMTest/CssSelect.php';
require __DIR__.'/DebugTestFramework.php';

\bdk\Debug::getInstance(array(
	'objectsExclude' => array('PHPUnit_Framework_TestSuite', 'PHPUnit\Framework\TestSuite'),
));

require __DIR__.'/Class/Test.php';
require __DIR__.'/Class/Test2.php';
if (version_compare(PHP_VERSION, '5.6', '>=')) {
    require __DIR__.'/Class/TestVariadic.php';
    if (!defined('HHVM_VERSION')) {
        // HHVM does not support variadic by reference
        require __DIR__.'/Class/TestVariadicByReference.php';
    }
}
