<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug;

/**
 * PHPDebugConsole autoloader
 */
class Autoloader
{

    protected $classMap = array();
    protected $psr4Map = array();

    /**
     * Register autoloader
     *
     * @return bool
     */
    public function register()
    {
        $this->psr4Map = array(
            'bdk\\Debug\\' => __DIR__,
            'bdk\\Container\\' => __DIR__ . '/../Container',
            'bdk\\ErrorHandler\\' => __DIR__ . '/../ErrorHandler',
            'bdk\\PubSub\\' => __DIR__ . '/../PubSub',
        );
        $this->classMap = array(
            'bdk\\Backtrace' => __DIR__ . '/../Backtrace/Backtrace.php',
            'bdk\\Container' => __DIR__ . '/../Container/Container.php',
            'bdk\\Debug\\Utility' => __DIR__ . '/Utility/Utility.php',
            'bdk\\ErrorHandler' => __DIR__ . '/../ErrorHandler/ErrorHandler.php',
        );
        return \spl_autoload_register(array($this, 'autoload'));
    }

    /**
     * Remove autoloader
     *
     * @return bool
     */
    public function unregister()
    {
        return \spl_autoload_unregister(array($this, 'autoload'));
    }

    /**
     * Debug class autoloader
     *
     * @param string $className classname to attempt to load
     *
     * @return void
     */
    protected function autoload($className)
    {
        $className = \ltrim($className, '\\'); // leading backslash _shouldn't_ have been passed
        if (isset($this->classMap[$className])) {
            require $this->classMap[$className];
            return;
        }
        foreach ($this->psr4Map as $namespace => $dir) {
            if (\strpos($className, $namespace) === 0) {
                $rel = \substr($className, \strlen($namespace));
                $rel = \str_replace('\\', '/', $rel);
                require $dir . '/' . $rel . '.php';
                return;
            }
        }
    }
}