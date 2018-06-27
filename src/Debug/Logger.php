<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2018 Brad Kent
 * @version   v2.3
 */

namespace bdk\Debug;

use bdk\Debug;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * PSR-3 Logger implementation
 */
class Logger extends AbstractLogger
{

    public $debug;

    /**
     * Constructor
     *
     * @param Debug $debug Debug instance
     */
    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param string        $level   debug, info, notice, warning, error, critical, alert, emergency
     * @param string|object $message message
     * @param array         $context array
     *
     * @return void
     * @throws InvalidArgumentException If invalid level.
     */
    public function log($level, $message, array $context = array())
    {
        if (!$this->isValidLevel($level)) {
            throw new InvalidArgumentException();
        }
        $str = $this->interpolate($message, $context);
        if (\in_array($level, array('emergency','critical','error'))) {
            $meta = $this->getMeta($context);
            $this->debug->error($str, $meta);
        } elseif (\in_array($level, array('warning','notice'))) {
            $meta = $this->getMeta($context);
            $this->debug->warn($str, $meta);
        } elseif ($level == 'alert') {
            $this->debug->alert($str);
        } elseif ($level == 'info') {
            $this->debug->info($str);
        } else {
            $this->debug->log($str);
        }
    }

    /**
     * Exctract potential meta values from $context
     *
     * @param array $context context array
     *
     * @return array meta
     */
    protected function getMeta($context)
    {
        $haveException = isset($context['exception'])
            && (
                $context['exception'] instanceof \Exception
                || PHP_VERSION_ID >= 70000 && $context['exception'] instanceof \Throwable
            );
        $metaVals = array();
        if ($haveException) {
            $metaVals = array(
                'backtrace' => $this->debug->errorHandler->backtrace($context['exception']),
                'file' => $context['exception']->getFile(),
                'line' => $context['exception']->getLine(),
            );
        } else {
            $callerInfo = $this->debug->utilities->getCallerInfo(1);
            $metaVals = array(
                'file' => $callerInfo['file'],
                'line' => $callerInfo['line'],
            );
            foreach (array('file','line') as $key) {
                if (isset($context[$key])) {
                    $metaVals[$key] = $context[$key];
                }
            }
        }
        return $this->debug->meta($metaVals);
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * @param string $message message
     * @param array  $context optional array of key/values
     *
     * @return string
     */
    protected function interpolate($message, array $context = array())
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            // check that the value can be casted to string
            if (!\is_array($val) && (!\is_object($val) || \method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return \strtr($message, $replace);
    }

    /**
     * Check if level is valid
     *
     * @param string $level debug level
     *
     * @return boolean
     */
    protected function isValidLevel($level)
    {
        return \in_array($level, $this->validLevels());
    }

    /**
     * Get list of valid levels
     *
     * @return array list of levels
     */
    protected function validLevels()
    {
        return array(
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        );
    }
}
