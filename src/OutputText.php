<?php
/**
 * Output log as plain-text
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2017 Brad Kent
 * @version   v1.4.0
 */

namespace bdk\Debug;

use bdk\Debug\Utf8;

/**
 * Output methods
 */
class OutputText implements PluginInterface
{

    private $debug;

    /**
     * Constructor
     *
     * @param object $debug debug instance
     * @param array  $cfg   config
     */
    public function __construct($debug)
    {
        $this->debug = $debug;
    }

    /**
     * {@inheritdoc}
     */
    public function debugListeners(\bdk\Debug $debug)
    {
        return array(
            'debug.output' => 'output',
        );
    }

    /**
     * Dump value as text
     *
     * @param mixed $val  value to dump
     * @param array $path {@internal}
     *
     * @return string
     */
    public function dump($val, $path = array())
    {
        $typeMore = null;
        $type = $this->debug->abstracter->getType($val, $typeMore);
        if ($typeMore == 'raw') {
            $val = $this->debug->abstracter->getAbstraction($val);
            $typeMore = 'abstraction';
        }
        if ($type == 'array') {
            $val = $this->dumpArray($val, $path);
        } elseif ($type == 'callable') {
            $val = $this->dumpCallable($val);
        } elseif ($type == 'object') {
            $val = $this->dumpObject($val, $path);
        } elseif ($typeMore == 'abstraction') {
            // resource
            $val = $val['value'];
        } elseif ($type == 'bool') {
            $val = $typeMore;   // 'true' or 'false'
        } elseif ($type == 'null') {
            $val = 'null';
        } elseif ($type == 'recursion') {
            $val = 'Array *RECURSION*';
        } elseif ($type == 'string') {
            if ($typeMore !== 'numeric') {
                $val = $this->debug->utf8->dump($val);
            }
            $val = '"'.$val.'"';
        } elseif ($type == 'undefined') {
            $val = 'undefined';
        }
        // float, int,: no modification required
        if (in_array($type, array('float','int')) || $typeMore == 'numeric') {
            $tsNow = time();
            $secs = 86400 * 90; // 90 days worth o seconds
            $num = trim($val, '"'); // remove quotes that were added to string
            if ($num > $tsNow - $secs && $num < $tsNow + $secs) {
                $date = date('Y-m-d H:i:s', $num);
                return $val.' ('.$date.')';
            }
        }
        return $val;
    }

    /**
     * Output the log as text
     *
     * @param Event $event event object
     *
     * @return string
     */
    public function output(Event $event = null)
    {
        $data = $this->debug->getData();
        $str = '';
        $depth = 0;
        foreach ($data['log'] as $args) {
            $method = array_shift($args);
            $str .= $this->processEntry($method, $args, $depth)."\n";
            if (in_array($method, array('group','groupCollapsed'))) {
                $depth ++;
            } elseif ($method == 'groupEnd' && $depth > 0) {
                $depth --;
            }
        }
        return $str;
    }

    /**
     * Return log entry for writing to file
     *
     * @param string  $method method
     * @param array   $args   arguments
     * @param integer $depth  group depth (for indentation)
     *
     * @return string
     */
    public function processEntry($method, $args = array(), $depth = 0)
    {
        if ($method == 'table' && count($args) == 2) {
            $caption = array_pop($args);
            array_unshift($args, $caption);
        }
        if (count($args) == 1 && is_string($args[0])) {
            $args[0] = strip_tags($args[0]);
        }
        foreach ($args as $k => $v) {
            if ($k > 0 || !is_string($v)) {
                $args[$k] = $this->dump($v);
            }
        }
        $num_args = count($args);
        $glue = ', ';
        if ($num_args == 2) {
            $glue = preg_match('/[=:] ?$/', $args[0])   // ends with "=" or ":"
                ? ''
                : ' = ';
        }
        $strIndent = str_repeat('    ', $depth);
        $str = implode($glue, $args);
        $str = $strIndent.str_replace("\n", "\n".$strIndent, $str);
        return $str;
    }

    /**
     * Dump an abstracted value
     *
     * @param array $abs  abstracted value
     * @param array $path path
     *
     * @return string
     */
    /*
    protected function dumpAbstraction($abs, $path)
    {
        $type = $abs['type'];
        if ($type == 'callable') {
            return $this->dumpCallable($abs);
        } elseif ($type == 'object') {
            return $this->dumpObject($abs, $path);
        } else {
            return $abs['value'];
        }
    }
    */

    /**
     * Dump array as text
     *
     * @param array $array array
     * @param array $path  {@internal} path to array... for proper indentation
     *
     * @return string
     */
    protected function dumpArray($array, $path = array())
    {
        $pathCount = count($path);
        foreach ($array as $key => $val) {
            $path[$pathCount] = $key;
            $array[$key] = $this->dump($val, $path);
        }
        $str = trim(print_r($array, true));
        $str = preg_replace('/Array\s+\(\s+\)/s', 'Array()', $str); // single-lineify empty arrays
        $str = str_replace("Array\n(", 'Array(', $str);
        if (count($path) > 1) {
            $str = str_replace("\n", "\n    ", $str);
        }
        return $str;
    }

    /**
     * Dump "Callable" as html
     *
     * @param array $abs array/callable abstraction
     *
     * @return string
     */
    protected function dumpCallable($abs)
    {
        return 'callable: '.$abs['values'][0].'::'.$abs['values'][1];
    }

    /**
     * Dump object as text
     *
     * @param array $abs  object "abstraction"
     * @param array $path (@internal)
     *
     * @return string
     */
    protected function dumpObject($abs, $path = array())
    {
        if (!empty($abs['info']) && $abs['info'] == 'recursion') {
            $str = '(object) '.$abs['class'].' *RECURSION*';
        } elseif (!empty($abs['info']) && $abs['info'] == 'excluded') {
            $str = '(object) '.$abs['class'].' (not inspected)';
        } else {
            $accessible = $abs['scopeClass'] == $abs['className']
                ? 'private'
                : 'public';
            $str = '(object) '.$abs['class']."\n";
            $propHeader = '';
            $properties = '';
            foreach ($abs['properties'] as $property => $info) {
                if ($accessible == 'public') {
                    if ($info['visibility'] != 'public') {
                        continue;
                    }
                    $properties .= '    '.$property.' = '.$info['value']."\n";
                } else {
                    $properties .= '    '.$info['visibility'].' '.$property.' = '.$info['value']."\n";
                }
            }
            if ($accessible == 'public') {
                $propHeader = $properties
                    ? 'Properties (only listing public)'
                    : 'No public properties';
            } else {
                $propHeader = $properties
                    ? 'Properties'
                    : 'No Properties';
            }
            $str .= '  '.$propHeader.':'."\n".$properties;
            $methodCount = 0;
            if ($abs['collectMethods'] && $this->debug->output->getCfg('outputMethods')) {
                if (!empty($abs['methods'])) {
                    foreach ($abs['methods'] as $info) {
                        if ($accessible == 'public' && $info['visibility'] !== 'public') {
                            continue;
                        }
                        $methodCount++;
                    }
                    if ($accessible == 'public') {
                        $str .= '  '.$methodCount.' Public Methods (not listed)'."\n";
                    } else {
                        $str .= '  '.$methodCount.' Methods (not listed)'."\n";
                    }
                } else {
                    $str .= '  No Methods'."\n";
                }
            }
        }
        if (count($path) > 1) {
            $str = str_replace("\n", "\n    ", $str);
        }
        $str = trim($str);
        return $str;
    }
}
