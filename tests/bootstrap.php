<?php
require __DIR__.'/../src/Debug.php';
require __DIR__.'/DOMTest/DOMTestCase.php';
require __DIR__.'/DOMTest/CssSelect.php';
require __DIR__.'/DebugTestFramework.php';

\bdk\Debug::getInstance();	// invoke autoloader

require __DIR__.'/Class/Test.php';
if (version_compare(PHP_VERSION, '5.6', '>=')) {
	require __DIR__.'/Class/TestVariadic.php';
	if (!defined('HHVM_VERSION')) {
		// HHVM does not support variadic by reference
		require __DIR__.'/Class/TestVariadicByReference.php';
	}
}
