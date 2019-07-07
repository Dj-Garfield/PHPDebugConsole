<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2019 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Output;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\LogEntry;
use bdk\Debug\Utilities;
use bdk\PubSub\Event;

/**
 * Output log to file
 */
class Stream extends Text
{

    protected $fileHandle;
    protected $streamCfg = array(
        'ansi' => false,
        'escapeCodes' => array(
            'arrayKey' => "\e[38;5;253m",
            'excluded' => "\e[38;5;9m",
            'false' => "\e[91m",
            'keyword' => "\e[38;5;45m",
            'muted' => "\e[38;5;250m",
            'numeric' => "\e[96m",
            'operator' => "\e[38;5;130m",
            'punct' => "\e[38;5;245m",
            'quote' => "\e[38;5;250m",
            'true' => "\e[32m",
        ),
        'escapeCodesLevels' => array(
            'danger' => "\e[38;5;88;48;5;203;1;4m",
            'info' => "\e[38;5;55;48;5;159;1;4m",
            'success' => "\e[38;5;22;48;5;121;1;4m",
            'warning' => "\e[38;5;1;48;5;230;1;4m",
        ),
        'escapeCodesMethods' => array(
            'error' => "\e[38;5;88;48;5;203m",
            'info' => "\e[38;5;55;48;5;159m",
            'warn' => "\e[38;5;1;48;5;230m",
        ),
        'streamUri' => 'php://stderr',
    );
    protected $escapeReset = "\e[0m";

    /**
     * Constructor
     *
     * @param Debug $debug Debug Instance
     */
    public function __construct(Debug $debug)
    {
        parent::__construct($debug);
        $this->cfg = Utilities::arrayMergeDeep($this->cfg, $this->streamCfg);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptions()
    {
        return array(
            'debug.config' => 'onConfig',
            'debug.log' => 'onLog',
            'debug.pluginInit' => 'init',
        );
    }

    /**
     * debug.pluginInit subscriber
     *
     * @return void
     */
    public function init()
    {
        $stream = $this->debug->getCfg('stream');
        $this->setStream($stream);
    }

    /**
     * debug.config event subscriber
     *
     * @param Event $event debug.config event object
     *
     * @return void
     */
    public function onConfig(Event $event)
    {
        $stream = $this->debug->getCfg('stream');
        $this->setStream($stream);
    }

    /**
     * debug.log event subscriber
     *
     * @param LogEntry $logEntry log entry instance
     *
     * @return void
     */
    public function onLog(LogEntry $logEntry)
    {
        if (!$this->fileHandle) {
            return;
        }
        $method = $logEntry['method'];
        if ($method == 'groupUncollapse') {
            return;
        }
        $isSummaryBookend = $method == 'groupSummary' || !empty($logEntry['meta']['closesSummary']);
        if ($isSummaryBookend) {
            $strIndent = \str_repeat('    ', $this->depth);
            \fwrite($this->fileHandle, $strIndent."\e[2m=========\e[0m\n");
            return;
        }
        if ($logEntry['args']) {
            $str = $this->processLogEntryViaEvent($logEntry);
            \fwrite($this->fileHandle, $str);
        } elseif ($method == 'groupEnd' && $this->depth > 0) {
            $this->depth --;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function processLogEntry(LogEntry $logEntry)
    {
        $method = $logEntry['method'];
        $escapeCode = isset($this->cfg['escapeCodesMethods'][$method])
            ? $this->cfg['escapeCodesMethods'][$method]
            : '';
        $this->escapeReset = $escapeCode ?: "\e[0m";
        $str = parent::processLogEntry($logEntry);
        if ($method == 'alert') {
            $level = $logEntry['meta']['level'];
            $escapeCode = $this->cfg['escapeCodesLevels'][$level];
        }
        if ($str && $escapeCode) {
            $strIndent = \str_repeat('    ', $this->depth);
            $str = preg_replace('#^('.$strIndent.')(.+)$#m', '$1'.$escapeCode.'$2'."\e[0m", $str);
        }
        return $str;
    }

    /**
     * Dump array as text
     *
     * @param array $array Array to display
     *
     * @return string
     */
    protected function dumpArray($array)
    {
        $isNested = $this->valDepth > 0;
        $this->valDepth++;
        if ($this->cfg['ansi']) {
            $escapeCodes = $this->cfg['escapeCodes'];
            $str = $escapeCodes['keyword'].'array'.$escapeCodes['punct'].'('.$this->escapeReset."\n";
            foreach ($array as $k => $v) {
                $escapeKey = \is_int($k)
                    ? $escapeCodes['numeric']
                    : $escapeCodes['arrayKey'];
                $str .= '    '
                    .$escapeCodes['punct'].'['.$escapeKey.$k.$escapeCodes['punct'].']'
                    .$escapeCodes['operator'].' => '.$this->escapeReset
                    .$this->dump($v)
                    ."\n";
            }
            $str .= $this->cfg['escapeCodes']['punct'].')'.$this->escapeReset;
            if (!$array) {
                $str = \str_replace("\n", '', $str)."\n";
            } elseif ($isNested) {
                $str = \str_replace("\n", "\n    ", $str);
            }
            return $str;
        } else {
            return parent::dumpArray();
        }
    }

    /**
     * Dump boolean
     *
     * @param boolean $val boolean value
     *
     * @return string
     */
    protected function dumpBool($val)
    {
        if ($this->cfg['ansi']) {
            return $val
                ? $this->cfg['escapeCodes']['true'].'true'.$this->escapeReset
                : $this->cfg['escapeCodes']['false'].'false'.$this->escapeReset;
        }
        return $val ? 'true' : 'false';
    }

    /**
     * Dump float value
     *
     * @param float $val float value
     *
     * @return float|string
     */
    protected function dumpFloat($val)
    {
        $date = $this->checkTimestamp($val);
        if ($this->cfg['ansi']) {
            $val = $this->cfg['escapeCodes']['numeric'].$val.$this->escapeReset;
        }
        return $date
            ? '📅 '.$val.' ('.$date.')'
            : $val;
    }

    /**
     * Dump null value
     *
     * @return string
     */
    protected function dumpNull()
    {
        return $this->cfg['ansi']
            ? $this->cfg['escapeCodes']['muted'].'null'.$this->escapeReset
            : 'null';
    }

    /**
     * Dump object as text
     *
     * @param Abstraction $abs object "abstraction"
     *
     * @return string
     */
    protected function dumpObject(Abstraction $abs)
    {
        $isNested = $this->valDepth > 0;
        $this->valDepth++;
        if ($this->cfg['ansi']) {
            $escapeCodes = $this->cfg['escapeCodes'];
            if ($abs['isRecursion']) {
                $str = $escapeCodes['excluded'].'*RECURSION*'.$this->escapeReset;
            } elseif ($abs['isExcluded']) {
                $str = $escapeCodes['excluded'].'NOT INSPECTED'.$this->escapeReset;
            } else {
                $str = $this->markupIdentifier($abs['className'])."\n";
                $str .= $this->cfg['ansi']
                    ? $this->dumpPropertiesAnsi($abs)
                    : $this->dumpProperties($abs);
                if ($abs['collectMethods'] && $this->debug->output->getCfg('outputMethods')) {
                    $str .= $this->dumpMethods($abs['methods']);
                }
            }
        } else {
            if ($abs['isRecursion']) {
                $str = '*RECURSION*';
            } elseif ($abs['isExcluded']) {
                $str = 'NOT INSPECTED';
            } else {
                $str = '(object) '.$abs['className']."\n";
                $str .= $this->dumpProperties($abs);
                if ($abs['collectMethods'] && $this->debug->output->getCfg('outputMethods')) {
                    $str .= $this->dumpMethods($abs['methods']);
                }
            }
        }
        $str = \trim($str);
        if ($isNested) {
            $str = \str_replace("\n", "\n    ", $str);
        }
        return $str;
    }

    /**
     * Dump object properties as text with ANSI escape codes
     *
     * @param Abstraction $abs object abstraction
     *
     * @return string
     */
    protected function dumpPropertiesAnsi(Abstraction $abs)
    {
        $str = '';
        $propHeader = '';
        if (isset($abs['methods']['__get'])) {
            $str .= "    \e[2m".'✨ This object has a __get() method'."\e[22m\n";
        }
        foreach ($abs['properties'] as $name => $info) {
            $vis = (array) $info['visibility'];
            foreach ($vis as $i => $v) {
                if (\in_array($v, array('magic','magic-read','magic-write'))) {
                    $vis[$i] = '✨ '.$v;    // "sparkles" there is no magic-wand unicode char
                } elseif ($v == 'private' && $info['inheritedFrom']) {
                    $vis[$i] = '🔒 '.$v;
                }
            }
            $vis = \implode(' ', $vis);
            $name = "\e[1m".$name."\e[22m";
            if ($info['debugInfoExcluded']) {
                $vis .= ' excluded';
                $str .= '    ('.$vis.') '.$name."\n";
            } else {
                $str .= '    ('.$vis.') '.$name.' '
                    .$this->cfg['escapeCodes']['operator'].'='.$this->escapeReset.' '
                    .$this->dump($info['value'])."\n";
            }
        }
        $propHeader = $str
            ? 'Properties:'
            : 'Properties: none!';
        return '  '.$propHeader."\n".$str;
    }

    /**
     * Dump string
     *
     * @param string $val string value
     *
     * @return string
     */
    protected function dumpString($val)
    {
        $escapeCodes = $this->cfg['escapeCodes'];
        if (\is_numeric($val)) {
            $date = $this->checkTimestamp($val);
            $val = $this->cfg['ansi']
                ? $escapeCodes['quote'].'"'
                    .$escapeCodes['numeric'].$val
                    .$escapeCodes['quote'].'"'
                    .$this->escapeReset
                : '"'.$val.'"';
            return $date
                ? '📅 '.$val.' ('.$date.')'
                : $val;
        } else {
            $ansiQuote = $escapeCodes['quote'].'"'.$this->escapeReset;
            return $this->cfg['ansi']
                ? $ansiQuote.$this->debug->utf8->dump($val).$ansiQuote
                : '"'.$this->debug->utf8->dump($val).'"';
        }
    }

    /**
     * Dump undefined
     *
     * @return null
     */
    protected function dumpUndefined()
    {
        return $this->cfg['ansi']
            ? "\e[2mundefined\e[22m"
            : 'undefined';
    }

    /**
     * Add ansi escape sequences for classname type strings
     *
     * @param string $str classname or classname(::|->)name (method/property/const)
     *
     * @return string
     */
    protected function markupIdentifier($str)
    {
        if (\preg_match('/^(.+)(::|->)(.+)$/', $str, $matches)) {
            $classname = $matches[1];
            $opIdentifier = $this->cfg['escapeCodes']['operator'].$matches[2].$this->escapeReset
                    . "\e[1m".$matches[3]."\e[22m";
        } else {
            $classname = $str;
            $opIdentifier = '';
        }
        $idx = \strrpos($classname, '\\');
        if ($idx) {
            $classname = $this->cfg['escapeCodes']['muted'].\substr($classname, 0, $idx + 1).$this->escapeReset
                ."\e[1m".\substr($classname, $idx + 1)."\e[22m";
        }
        return $classname.$opIdentifier;
    }

    /**
     * Set file we will write to
     *
     * @param resource|string $stream file path, uri, or stream resource
     *
     * @return void
     */
    protected function setStream($stream)
    {
        /*
        if ($stream === $this->cfg['streamUri']) {
            // no change
            return;
        }
        */
        if ($this->fileHandle) {
            $meta = \stream_get_meta_data($this->fileHandle);
            if ($meta['uri'] === $stream) {
                return;
            }
        }
        if ($this->fileHandle) {
            // close existing file
            \fclose($this->fileHandle);
            $this->fileHandle = null;
        }
        $this->cfg['streamUri'] = $stream;
        if (!$stream) {
            return;
        }
        $uriExists = \file_exists($stream);
        $this->fileHandle = \fopen($stream, 'a');
        if ($this->fileHandle) {
            \fwrite($this->fileHandle, '***** '.\date('Y-m-d H:i:s').' *****'."\n");
            if (!$uriExists) {
                // we just created file
                // \chmod($stream, 0660);
            }
        }
    }
}
