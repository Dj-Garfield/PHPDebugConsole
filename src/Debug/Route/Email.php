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

namespace bdk\Debug\Route;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\LogEntry;
use bdk\Debug\Route\RouteInterface;
use bdk\PubSub\Event;

/**
 * Email log
 */
class Email implements RouteInterface
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
     * {@inheritDoc}
     */
    public function appendsHeaders()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getSubscriptions()
    {
        return array(
            Debug::EVENT_OUTPUT => 'processLogEntries',
        );
    }

    /**
     * Serializes and emails log
     *
     * @param Event $event Generic event having Debug instance as subject
     *
     * @return void
     */
    public function processLogEntries(Event $event)
    {
        $debug = $event->getSubject();
        $subject = $this->buildSubject();
        $body = $this->buildBody();
        $this->debug->email($debug->getCfg('emailTo', Debug::CONFIG_DEBUG), $subject, $body);
    }

    /**
     * {@inheritDoc}
     */
    public function processLogEntry(LogEntry $logEntry)
    {
    }

    /**
     * serialize log for emailing
     *
     * @param array $data log data to serialize
     *
     * @return string
     */
    public static function serializeLog($data)
    {
        foreach (array('alerts','log','logSummary') as $what) {
            foreach ($data[$what] as $i => $v) {
                if ($what === 'logSummary') {
                    foreach ($v as $i2 => $logEntry) {
                        $data['logSummary'][$i][$i2] = \array_values($logEntry->export());
                    }
                    continue;
                }
                $data[$what][$i] = \array_values($v->export());
            }
        }
        $str = \serialize($data);
        if (\function_exists('gzdeflate')) {
            $str = \gzdeflate($str);
        }
        $str = \chunk_split(\base64_encode($str), 124);
        return "START DEBUG\n"
            . $str    // chunk_split appends a "\r\n"
            . 'END DEBUG';
    }

    /**
     * Use to unserialize the log serialized by emailLog
     *
     * @param string $str   serialized log data
     * @param Debug  $debug (optional) Debug instance
     *
     * @return array|false
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public static function unserializeLog($str, Debug $debug = null)
    {
        if (!$debug) {
            $debug = Debug::getInstance();
        }
        $strStart = 'START DEBUG';
        $strEnd = 'END DEBUG';
        $regex = '/' . $strStart . '[\r\n]+(.+)[\r\n]+' . $strEnd . '/s';
        $matches = array();
        if (\preg_match($regex, $str, $matches)) {
            $str = $matches[1];
        }
        $str = $debug->stringUtil->isBase64Encoded($str)
            ? \base64_decode($str)
            : false;
        if ($str && \function_exists('gzinflate')) {
            $strInflated = \gzinflate($str);
            if ($strInflated) {
                $str = $strInflated;
            }
        }
        $data = self::unserializeSafe($str, array(
            'bdk\\Debug\\Abstraction\\Abstraction',
        ));
        return self::unserializeLogLogEntrify($debug, $data);
    }

    /**
     * Build email body
     *
     * @return string
     */
    private function buildBody()
    {
        $request = $this->debug->request;
        $serverParams = $request->getServerParams();
        $body = (isset($serverParams['REQUEST_METHOD'])
            ? 'Request: ' . $serverParams['REQUEST_METHOD'] . ' ' . $this->debug->redact((string) $request->getUri())
            : 'Command: ' . \implode(' ', $serverParams['argv'])
        ) . "\n\n";

        /*
            List errors that occured
        */
        $errorStr = $this->buildErrorList();
        if ($errorStr) {
            $body .= 'Error(s):' . "\n"
                . $errorStr . "\n";
        }
        /*
            "attach" serialized log to body
        */
        $data = \array_intersect_key($this->debug->getData(), \array_flip(array(
            'alerts',
            'log',
            'logSummary',
            'requestId',
            'runtime',
        )));
        $data['channelNameRoot'] = $this->debug->rootInstance->getCfg('channelName', Debug::CONFIG_DEBUG);
        $data['channels'] = \array_map(function (Debug $channel) {
            return array(
                'channelIcon' => $channel->getCfg('channelIcon', Debug::CONFIG_DEBUG),
                'channelShow' => $channel->getCfg('channelShow', Debug::CONFIG_DEBUG),
            );
        }, $this->debug->getChannels(true));
        $data['config'] = array(
            'logRuntime' => $this->debug->getCfg('logRuntime', Debug::CONFIG_DEBUG),
        );
        $debugClass = \get_class($this->debug);
        $data['version'] = $debugClass::VERSION;
        \ksort($data);
        return $body . self::serializeLog($data);
    }

    /**
     * Build email subject
     *
     * @return string
     */
    private function buildSubject()
    {
        $subject = 'Debug Log';
        $subjectMore = '';
        $haveError = (bool) $this->debug->errorHandler->getLastError();
        $serverParams = $this->debug->request->getServerParams();
        if (!empty($serverParams['HTTP_HOST'])) {
            $subjectMore .= ' ' . $serverParams['HTTP_HOST'];
        }
        if ($haveError) {
            $subjectMore .= ' ' . ($subjectMore ? '(Error)' : 'Error');
        }
        $subject = \rtrim($subject . ':' . $subjectMore, ':');
        return $subject;
    }

    /**
     * Build list of errors for email
     *
     * @return string
     */
    private function buildErrorList()
    {
        $errorStr = '';
        $errors = $this->debug->errorHandler->get('errors');
        \uasort($errors, function ($err1, $err2) {
            return \strcmp($err1['file'] . $err1['line'], $err2['file'] . $err2['line']);
        });
        $lastFile = '';
        foreach ($errors as $error) {
            if ($error['isSuppressed']) {
                continue;
            }
            if ($error['file'] !== $lastFile) {
                $errorStr .= $error['file'] . ':' . "\n";
                $lastFile = $error['file'];
            }
            $typeStr = $error['type'] === E_STRICT
                ? 'Strict'
                : $error['typeStr'];
            $errorStr .= \sprintf(' Line %s: (%s) %s', $error['line'], $typeStr, $error['message']) . "\n";
        }
        return $errorStr;
    }

    /**
     * Convert pre 3.0 serialized log entry args to 3.0
     *
     * Prior to to v3.0, abstractions were stored as an array
     * Find these arrays and convert them to Abstraction objects
     *
     * @param array $args log entry args (unserialized)
     *
     * @return array
     */
    private static function unserializeLogBackward($args)
    {
        foreach ($args as $k => $v) {
            if (!\is_array($v)) {
                continue;
            }
            if (isset($v['debug']) && $v['debug'] === Abstracter::ABSTRACTION) {
                $type = $v['type'];
                unset($v['debug'], $v['type']);
                if ($type === Abstracter::TYPE_OBJECT) {
                    $v['properties'] = self::unserializeLogBackward($v['properties']);
                }
                $args[$k] = new Abstraction($type, $v);
                continue;
            }
            $args[$k] = self::unserializeLogBackward($v);
        }
        return $args;
    }

    /**
     * for unserialized log, Convert logEntry arrays to log entry objects
     *
     * @param Debug $debug Debug instance
     * @param array $data  unserialized log data
     *
     * @return array log data
     */
    private static function unserializeLogLogEntrify(Debug $debug, $data)
    {
        $ver = isset($data['version'])
            ? $data['version']
            : '2.3' ;
        $backward = \version_compare($ver, '3.0', '<');
        foreach (array('alerts','log','logSummary') as $what) {
            foreach ($data[$what] as $i => $v) {
                if ($what === 'logSummary') {
                    foreach ($v as $i2 => $v2) {
                        if ($backward) {
                            $v2[1] = self::unserializeLogBackward($v2[1]);
                        }
                        $data['logSummary'][$i][$i2] = new LogEntry($debug, $v2[0], $v2[1], $v2[2]);
                    }
                    continue;
                }
                if ($backward) {
                    $v[1] = self::unserializeLogBackward($v[1]);
                }
                $data[$what][$i] = new LogEntry($debug, $v[0], $v[1], $v[2]);
            }
        }
        return $data;
    }

    /**
     * Unserialize while only allowing the specified classes to be unserialized
     *
     * @param string   $str            serialized string
     * @param string[] $allowedClasses allowed class names
     *
     * @return mixed
     */
    private static function unserializeSafe($str, $allowedClasses = array())
    {
        if (\version_compare(PHP_VERSION, '7.0', '>=')) {
            // 2nd param is PHP >= 7.0 (get a warning: unserialize() expects exactly 1 parameter, 2 given)
            return \unserialize($str, array(
                'allowed_classes' => $allowedClasses,
            ));
        }
        // There's a possibility this pattern may be found inside a string (false positive)
        $regex = '#[CO]:(\d+):"([\w\\\\]+)":\d+:#';
        \preg_match_all($regex, $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $set) {
            if (\strlen($set[2]) !== $set[1]) {
                continue;
            } elseif (!\in_array($set[2], $allowedClasses)) {
                return false;
            }
        }
        return \unserialize($str);
    }
}
