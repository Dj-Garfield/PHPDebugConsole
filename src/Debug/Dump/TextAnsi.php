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

namespace bdk\Debug\Dump;

use bdk\Debug;
use bdk\Debug\LogEntry;
use bdk\Debug\Utilities;
use bdk\Debug\Abstraction\Abstraction;

/**
 * Base output plugin
 */
class TextAnsi extends Text
{

    protected $ansiCfg = array(
        'ansi' => 'default',    // default | true | false  (STDOUT & STDERR streams will default to true)
        'escapeCodes' => array(
            'excluded' => "\e[38;5;9m",     // red
            'false' => "\e[91m",            // red
            'keyword' => "\e[38;5;45m",     // blue
            'arrayKey' => "\e[38;5;83m",    // yellow
            'muted' => "\e[38;5;250m",      // dark grey
            'numeric' => "\e[96m",          // blue
            'operator' => "\e[38;5;130m",   // green
            'punct' => "\e[38;5;245m",      // grey  (brackets)
            'property' => "\e[38;5;83m",    // yellow
            'quote' => "\e[38;5;250m",      // grey
            'true' => "\e[32m",             // green
            'recursion' => "\e[38;5;196m",  // red
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
        'glue' => array(
            'multiple' => "\e[38;5;245m, \x00escapeReset\x00",
            'equal' => " \e[38;5;245m=\x00escapeReset\x00 ",
        ),
        'stream' => 'php://stderr',   // filepath/uri/resource
    );
    protected $escapeReset = "\e[0m";

    const ESCAPE_RESET = "\x00escapeReset\x00";

    /**
     * Constructor
     *
     * @param Debug $debug Debug Instance
     */
    public function __construct(Debug $debug)
    {
        parent::__construct($debug);
        $this->cfg = Utilities::arrayMergeDeep($this->cfg, $this->ansiCfg);
    }

    /**
     * Add ansi escape sequences for classname type strings
     *
     * @param string $str classname or classname(::|->)name (method/property/const)
     *
     * @return string
     */
    public function markupIdentifier($str)
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
     * Return log entry as text
     *
     * @param LogEntry $logEntry log entry instance
     *
     * @return string
     */
    public function processLogEntry(LogEntry $logEntry)
    {
        $method = $logEntry['method'];
        $escapeCode = '';
        if ($method == 'alert') {
            $level = $logEntry->getMeta('level');
            $escapeCode = $this->cfg['escapeCodesLevels'][$level];
        } elseif (isset($this->cfg['escapeCodesMethods'][$method])) {
            $escapeCode = $this->cfg['escapeCodesMethods'][$method];
        } elseif ($method == 'groupSummary' || $logEntry->getMeta('closesSummary')) {
            $escapeCode = "\e[2m";
        }
        $this->escapeReset = $escapeCode ?: "\e[0m";
        $str = parent::processLogEntry($logEntry);
        $str = str_replace(self::ESCAPE_RESET, $this->escapeReset, $str);
        if ($str && $escapeCode) {
            $strIndent = \str_repeat('    ', $this->depth);
            $str = \preg_replace('#^('.$strIndent.')(.+)$#m', '$1'.$escapeCode.'$2'."\e[0m", $str);
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
            $str = \str_replace("\n", '', $str);
        } elseif ($isNested) {
            $str = \str_replace("\n", "\n    ", $str);
        }
        return $str;
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
        return $val
            ? $this->cfg['escapeCodes']['true'].'true'.$this->escapeReset
            : $this->cfg['escapeCodes']['false'].'false'.$this->escapeReset;
    }

    /**
     * Dump callable
     *
     * @param Abstraction $abs array/callable abstraction
     *
     * @return string
     */
    protected function dumpCallable(Abstraction $abs)
    {
        return 'callable: '.$this->markupIdentifier($abs['values'][0].'::'.$abs['values'][1]);
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
        $val = $this->cfg['escapeCodes']['numeric'].$val.$this->escapeReset;
        return $date
            ? '📅 '.$val.' '.$this->cfg['escapeCodes']['muted'].'('.$date.')'.$this->escapeReset
            : $val;
    }

    /**
     * Dump object methods as text
     *
     * @param array $methods methods as returned from getMethods
     *
     * @return string html
     */
    protected function dumpMethods($methods)
    {
        $str = '';
        $counts = array(
            'public' => 0,
            'protected' => 0,
            'private' => 0,
            'magic' => 0,
        );
        foreach ($methods as $info) {
            $counts[ $info['visibility'] ] ++;
        }
        foreach ($counts as $vis => $count) {
            if ($count) {
                $str .= '    '.$vis
                    .$this->cfg['escapeCodes']['punct'].':'.$this->escapeReset.' '
                    .$this->cfg['escapeCodes']['numeric'].$count
                    .$this->escapeReset."\n";
            }
        }
        $header = $str
            ? "\e[4mMethods:\e[24m"
            : 'Methods: none!';
        return '  '.$header."\n".$str;
    }

    /**
     * Dump null value
     *
     * @return string
     */
    protected function dumpNull()
    {
        return $this->cfg['escapeCodes']['muted'].'null'.$this->escapeReset;
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
        $escapeCodes = $this->cfg['escapeCodes'];
        if ($abs['isRecursion']) {
            $str = $escapeCodes['excluded'].'*RECURSION*'.$this->escapeReset;
        } elseif ($abs['isExcluded']) {
            $str = $escapeCodes['excluded'].'NOT INSPECTED'.$this->escapeReset;
        } else {
            $str = $this->markupIdentifier($abs['className'])."\n";
            $str .= $this->dumpProperties($abs);
            if ($abs['collectMethods'] && $this->debug->getCfg('outputMethods')) {
                $str .= $this->dumpMethods($abs['methods']);
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
    protected function dumpProperties(Abstraction $abs)
    {
        $str = '';
        if (isset($abs['methods']['__get'])) {
            $str .= '    '.$this->cfg['escapeCodes']['muted']
                .'✨ This object has a __get() method'
                .$this->escapeReset
                ."\n";
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
            $name = $this->cfg['escapeCodes']['property'].$name.$this->escapeReset;
            if ($info['debugInfoExcluded']) {
                $vis .= ' excluded';
                $str .= '    ('.$vis.') '.$name."\n";
            } else {
                $str .= '    ('.$vis.') '.$name.' '
                    .$this->cfg['escapeCodes']['operator'].'='.$this->escapeReset.' '
                    .$this->dump($info['value'])."\n";
            }
        }
        $header = $str
            ? "\e[4mProperties:\e[24m"
            : 'Properties: none!';
        return '  '.$header."\n".$str;
    }

    /**
     * Dump recursion (array recursion)
     *
     * @return string
     */
    protected function dumpRecursion()
    {
        return $this->cfg['escapeCodes']['keyword'].'array '
            .$this->cfg['escapeCodes']['recursion'].'*RECURSION*'
            .$this->escapeReset;
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
            $val = $escapeCodes['quote'].'"'
                .$escapeCodes['numeric'].$val
                .$escapeCodes['quote'].'"'
                .$this->escapeReset;
            return $date
                ? '📅 '.$val.' '.$escapeCodes['muted'].'('.$date.')'.$this->escapeReset
                : $val;
        } else {
            $ansiQuote = $escapeCodes['quote'].'"'.$this->escapeReset;
            $val = $this->debug->utf8->dump($val);
            if ($this->argStringOpts['addQuotes']) {
                $val = $ansiQuote.$val.$ansiQuote;
            }
            return $val;
        }
    }

    /**
     * Dump undefined
     *
     * @return null
     */
    protected function dumpUndefined()
    {
        return "\e[2mundefined\e[22m";
    }
}
