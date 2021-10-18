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

/**
 * "Crate" values for transport via WAMP
 */
class WampCrate
{

    private $debug;
    private $detectFiles = false;
    private $foundFiles = array();

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
     * JSON doesn't handle binary well (at all)
     *     a) strings with invalid utf-8 can't be json_encoded
     *     b) "javascript has a unicode problem" / will munge strings
     *   base64_encode all strings!
     *
     * Associative arrays get JSON encoded to js objects...
     *     Javascript doesn't maintain order for object properties
     *     in practice this seems to only be an issue with int/numeric keys
     *     store key order if needed
     *
     * @param mixed $mixed       value to crate
     * @param bool  $detectFiles Should we test if strings are filepaths?
     *
     * @return array|string
     */
    public function crate($mixed, $detectFiles = false)
    {
        $this->detectFiles = $detectFiles;
        if (\is_array($mixed)) {
            return $this->crateArray($mixed);
        }
        if (\is_string($mixed)) {
            return $this->crateString($mixed);
        }
        if ($mixed instanceof Abstraction) {
            $clone = clone $mixed;
            switch ($mixed['type']) {
                case Abstracter::TYPE_ARRAY:
                    $clone['value'] = $this->crateArray($clone['value']);
                    return $clone;
                case Abstracter::TYPE_OBJECT:
                    return $this->crateObject($clone);
                case Abstracter::TYPE_STRING:
                    $clone['value'] = $this->crateString(
                        $clone['value'],
                        $clone['typeMore'] === Abstracter::TYPE_STRING_BINARY
                    );
                    if ($clone['typeMore'] === Abstracter::TYPE_STRING_BINARY) {
                        // PITA to get strlen in javascript
                        // pass the length of captured value
                        $clone['strlenValue'] = \strlen($mixed['value']);
                    }
                    if (isset($clone['valueDecoded'])) {
                        $clone['valueDecoded'] = $this->crate($clone['valueDecoded']);
                    }
                    return $clone;
            }
            return $clone;
        }
        return $mixed;
    }

    /**
     * Crate LogEntry
     *
     * @param LogEntry $logEntry LogEntry instance
     *
     * @return array
     */
    public function crateLogEntry(LogEntry $logEntry)
    {
        $detectFiles = $logEntry->getMeta('detectFiles', false);
        $args = $this->crate($logEntry['args'], $detectFiles);
        $meta = $logEntry['meta'];
        if (!empty($meta['trace'])) {
            $logEntryTmp = new LogEntry(
                $this->debug,
                'trace',
                array($meta['trace']),
                array(
                    'columns' => array('file','line','function'),
                    'inclContext' => $logEntry->getMeta('inclContext', false),
                )
            );
            $this->debug->methodTable->doTable($logEntryTmp);
            unset($args[2]);
            $meta = \array_merge($meta, array(
                'caption' => 'trace',
                'tableInfo' => $logEntryTmp['meta']['tableInfo'],
                'trace' => $logEntryTmp['args'][0],
            ));
        }
        if ($detectFiles) {
            $meta['foundFiles'] = $this->foundFiles();
        }
        return array($args, $meta);
    }

    /**
     * Returns files found during crating
     *
     * @return array
     */
    public function foundFiles()
    {
        $foundFiles = $this->foundFiles;
        $this->foundFiles = array();
        return $foundFiles;
    }

    /**
     * Crate array (may be encapsulated by Abstraction)
     *
     * @param array $array array
     *
     * @return array
     */
    private function crateArray($array)
    {
        $return = array();
        $keys = array();
        foreach ($array as $k => $v) {
            if (\is_string($k) && \substr($k, 0, 1) === "\x00") {
                // key starts with null...
                // php based wamp router will choke (attempt to json_decode to obj)
                $k = '_b64_:' . \base64_encode($k);
            }
            $return[$k] = $this->crate($v);
        }
        if ($this->debug->arrayUtil->isList($array) === false) {
            /*
                Compare sorted vs unsorted
                if differ pass the key order
            */
            $keys = \array_keys($array);
            $keysSorted = $keys;
            \sort($keysSorted, SORT_STRING);
            if ($keys !== $keysSorted) {
                $return['__debug_key_order__'] = $keys;
            }
        }
        return $return;
    }

    /**
     * Crate object abstraction
     * (make sure string values are base64 encoded when necessary)
     *
     * @param Abstraction $abs Object abstraction
     *
     * @return array
     */
    private function crateObject(Abstraction $abs)
    {
        $info = $abs->jsonSerialize();
        foreach ($info['properties'] as $k => $propInfo) {
            $info['properties'][$k]['value'] = $this->crate($propInfo['value']);
        }
        if (isset($info['methods']['__toString'])) {
            $info['methods']['__toString'] = $this->crate($info['methods']['__toString']);
        }
        return $info;
    }

    /**
     * Base64 encode string if it contains non-utf8 characters
     *
     * @param string $str       string
     * @param bool   $isNotUtf8 does string contain non-utf8 chars?
     *
     * @return string
     */
    private function crateString($str, $isNotUtf8 = false)
    {
        if (!$str) {
            return $str;
        }
        if ($isNotUtf8) {
            return '_b64_:' . \base64_encode($str);
        }
        if ($this->detectFiles && $this->debug->utility->isFile($str)) {
            $this->foundFiles[] = $str;
        }
        return $str;
    }
}