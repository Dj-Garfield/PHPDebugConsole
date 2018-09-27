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

namespace bdk\Debug\Output;

use bdk\Debug;
use bdk\Debug\MethodTable;
use bdk\PubSub\Event;

/**
 * Output log as HTML
 *
 * @property HtmlObject $object lazy-loaded HtmlObject... only loaded if dumping an object
 */
class Html extends Base
{

    protected $errorSummary;
    protected $wrapAttribs = array();
    protected $channels = array();

    /**
     * Constructor
     *
     * @param \bdk\Debug $debug debug instance
     */
    public function __construct(Debug $debug)
    {
        $this->errorSummary = new HtmlErrorSummary($this, $debug->errorHandler);
        parent::__construct($debug);
    }

    /**
     * Formats an array as a table
     *
     * @param array  $rows    array of \Traversable
     * @param string $caption optional caption
     * @param array  $columns columns to display
     * @param array  $attribs default attributes
     *
     * @return string
     */
    public function buildTable($rows, $caption = null, $columns = array(), $attribs = array())
    {
        if (!\is_array($rows) || empty($rows)) {
            // empty array/value
            return '<div class="m_log">'
                .(isset($caption) ? $caption.' = ' : '')
                .$this->dump($rows)
                .'</div>';
        }
        if ($this->debug->abstracter->isAbstraction($rows) && $rows['traverseValues']) {
            $caption .= ' ('.$this->markupClassname($rows['className'], 'span', array(
                    'title' => $rows['phpDoc']['summary'] ?: null,
                )).')';
            $caption = \trim($caption);
            $rows = $rows['traverseValues'];
        }
        $keys = $columns ?: $this->debug->methodTable->colKeys($rows);
        $this->tableInfo = array(
            'haveObjRow' => false,
            'colClasses' => \array_fill_keys($keys, null),
        );
        $tBody = '';
        foreach ($rows as $k => $row) {
            $tBody .= $this->buildTableRow($row, $keys, $k);
        }
        if (!$this->tableInfo['haveObjRow']) {
            $tBody = \str_replace('<td class="t_classname"></td>', '', $tBody);
        }
        if (\is_string($attribs)) {
            $attribs = array(
                'class' => $attribs,
            );
        }
        return $this->debug->utilities->buildTag(
            'table',
            $attribs,
            "\n"
                .($caption ? '<caption>'.$caption.'</caption>'."\n" : '')
                .'<thead>'."\n".$this->buildTableHeader($keys).'</thead>'."\n"
                .'<tbody>'."\n".$tBody.'</tbody>'."\n"
        );
    }

    /**
     * Dump value as html
     *
     * @param mixed        $val      value to dump
     * @param array        $path     {@internal}
     * @param boolean      $sanitize (true) apply htmlspecialchars?
     * @param string|false $tagName  (span) tag to wrap value in (or false)
     *
     * @return string
     */
    public function dump($val, $path = array(), $sanitize = true, $tagName = 'span')
    {
        $this->wrapAttribs = array(
            'class' => array(),
            'title' => null,
        );
        $this->sanitize = $sanitize;
        $val = parent::dump($val);
        if ($tagName && !\in_array($this->dumpType, array('recursion'))) {
            $wrapAttribs = $this->debug->utilities->arrayMergeDeep(
                array(
                    'class' => array(
                        't_'.$this->dumpType,
                        $this->dumpTypeMore,
                    ),
                ),
                $this->wrapAttribs
            );
            $val = $this->debug->utilities->buildTag($tagName, $wrapAttribs, $val);
        }
        $this->wrapAttribs = array();
        return $val;
    }

    /**
     * Wrap classname in span.t_classname
     * if namespaced'd additionally wrap namespace in span.namespace
     * If callable, also wrap .t_operator and .t_method-name
     *
     * @param string $str     classname or classname(::|->)methodname
     * @param string $tagName ("span") html tag to use
     * @param array  $attribs additional html attributes
     *
     * @return string
     */
    public function markupClassname($str, $tagName = 'span', $attribs = array())
    {
        if (\preg_match('/^(.+)(::|->)(.+)$/', $str, $matches)) {
            $classname = $matches[1];
            $opMethod = '<span class="t_operator">'.\htmlspecialchars($matches[2]).'</span>'
                    . '<span class="method-name">'.$matches[3].'</span>';
        } else {
            $classname = $str;
            $opMethod = '';
        }
        $idx = \strrpos($classname, '\\');
        if ($idx) {
            $classname = '<span class="namespace">'.\substr($classname, 0, $idx + 1).'</span>'
                . \substr($classname, $idx + 1);
        }
        $attribs = \array_merge(array(
            'class' => 't_classname',
        ), $attribs);
        return $this->debug->utilities->buildTag($tagName, $attribs, $classname)
            .$opMethod;
    }

    /**
     * Return the log as HTML
     *
     * @param Event $event event object
     *
     * @return string|void
     */
    public function onOutput(Event $event)
    {
        $this->data = $this->debug->getData();
        $str = '<div class="debug">'."\n";
        if ($this->debug->getCfg('output.outputCss')) {
            $str .= '<style type="text/css">'."\n"
                    .$this->debug->output->getCss()."\n"
                .'</style>'."\n";
        }
        if ($this->debug->getCfg('output.outputScript')) {
            $str .= '<script type="text/javascript">'
                .\file_get_contents($this->debug->getCfg('filepathScript'))
                .'</script>'."\n";
        }
        $str .= '<div class="debug-bar"><h3>Debug Log</h3></div>'."\n";
        $str .= '{{channelToggles}}'; // initially display:none;
        $str .= $this->processAlerts();
        /*
            If outputing script, initially hide the output..
            this will help page load performance (fewer redraws)... by magnitudes
        */
        if ($this->debug->getCfg('output.outputScript')) {
            $str .= '<div class="loading">Loading <i class="fa fa-spinner fa-pulse fa-2x fa-fw" aria-hidden="true"></i></div>'."\n";
        }
        $str .= '<div class="debug-header m_group"'.($this->debug->getCfg('outputScript') ? ' style="display:none;"' : '').'>'."\n";
        $str .= $this->processSummary();
        $str .= '</div>'."\n";
        $str .= '<div class="debug-content m_group"'.($this->debug->getCfg('outputScript') ? ' style="display:none;"' : '').'>'."\n";
        $str .= $this->processLog();
        $str .= '</div>'."\n";  // close .debug-content
        $str .= '</div>'."\n";  // close .debug
        $str = \strtr($str, array(
            '{{channelToggles}}' => $this->getChannelToggles(),
        ));
        $this->data = array();
        $event['return'] .= $str;
    }

    /**
     * Return a log entry as HTML
     *
     * @param string $method method
     * @param array  $args   args
     * @param array  $meta   meta values
     *
     * @return string|void
     */
    public function processLogEntry($method, $args = array(), $meta = array())
    {
        $str = '';
        if (!\in_array($meta['channel'], $this->channels)) {
            $this->channels[] = $meta['channel'];
        }
        if ($meta['channel'] === $this->channelNameRoot) {
            $meta['channel'] = null;
        }
        if ($method == 'alert') {
            $str = $this->methodAlert($args, $meta);
        } elseif (\in_array($method, array('group', 'groupCollapsed', 'groupEnd'))) {
            $str = $this->buildGroupMethod($method, $args, $meta);
        } elseif (\in_array($method, array('table','trace'))) {
            $str = $this->buildTable(
                $args[0],
                $meta['caption'],
                $meta['columns'],
                array(
                    'class' => array(
                        $method == 'table' ? 'm_table' : 'm_trace',
                        'table-bordered',
                        !empty($meta['sortable']) ? 'sortable' : null,
                    ),
                    'data-channel' => $meta['channel'],
                )
            );
        } else {
            $attribs = array(
                'class' => 'm_'.$method,
                'data-channel' => $meta['channel'],
                'title' => isset($meta['file'])
                    ? $meta['file'].': line '.$meta['line']
                    : null,
            );
            if (\in_array($method, array('assert','clear','error','info','log','warn'))) {
                if (\in_array($method, array('error','warn'))) {
                    if (isset($meta['errorCat'])) {
                        $attribs['class'] .= ' error-'.$meta['errorCat'];
                    }
                }
                if (\count($args) > 1 && \is_string($args[0])) {
                    $hasSubs = false;
                    $args = $this->processSubstitutions($args, $hasSubs);
                    if ($hasSubs) {
                        $args = array( \implode('', $args) );
                    }
                }
            }
            $str = $this->debug->utilities->buildTag(
                'div',
                $attribs,
                $this->buildArgString($args)
            );
        }
        $str = str_replace(' data-channel="null"', '', $str);
        $str .= "\n";
        return $str;
    }

    /**
     * Convert all arguments to html and join them together.
     *
     * @param array $args arguments
     *
     * @return string html
     */
    protected function buildArgString($args)
    {
        $glue = ', ';
        if (\count($args) == 2 && \is_string($args[0])) {
            $glue = \preg_match('/[=:] ?$/', $args[0])   // ends with "=" or ":"
                ? ''
                : ' = ';
        }
        foreach ($args as $i => $v) {
            if ($i > 0) {
                $args[$i] = $this->dump($v, array(), true);
            } else {
                // don't apply htmlspecialchars()
                $args[$i] = $this->dump($v, array(), false);
            }
        }
        return \implode($glue, $args);
    }

    /**
     * handle html output of group, groupCollapsed, & groupEnd
     *
     * @param string $method group|groupCollapsed|groupEnd
     * @param array  $args   args passed to method
     * @param array  $meta   meta values
     *
     * @return string
     */
    protected function buildGroupMethod($method, $args = array(), $meta = array())
    {
        $str = '';
        if (\in_array($method, array('group','groupCollapsed'))) {
            $label = \array_shift($args);
            $levelClass = isset($meta['level'])
                ? 'level-'.$meta['level']
                : null;
            if (!empty($meta['isMethodName'])) {
                $label = $this->markupClassname($label);
            }
            foreach ($args as $k => $v) {
                $args[$k] = $this->dump($v);
            }
            $argStr = \implode(', ', $args);
            /*
                Header
            */
            $str .= $this->debug->utilities->buildTag(
                'div',
                array(
                    'class' => array(
                        'group-header',
                        $method == 'groupCollapsed'
                            ? 'collapsed'
                            : 'expanded',
                        $levelClass,
                    ),
                    'data-channel' => $meta['channel'],
                ),
                '<span class="group-label">'
                    .$label
                    .( !empty($argStr)
                        ? '(</span>'.$argStr.'<span class="group-label">)'
                        : '' )
                .'</span>'
            )."\n";
            /*
                Group open
            */
            $str .= '<div'.$this->debug->utilities->buildAttribString(array(
                'class' => array(
                    'm_group',
                    $levelClass,
                ),
            )).'>';
        } elseif ($method == 'groupEnd') {
            $str = '</div>';
        }
        return $str;
    }

    /**
     * Returns table's thead row
     *
     * @param array $keys column header values (keys of array or property names)
     *
     * @return string
     */
    protected function buildTableHeader($keys)
    {
        $headers = array();
        foreach ($keys as $key) {
            $headers[$key] = $key === MethodTable::SCALAR
                ? 'value'
                : \htmlspecialchars($key);
            if ($this->tableInfo['colClasses'][$key]) {
                $headers[$key] .= ' '.$this->markupClassname($this->tableInfo['colClasses'][$key]);
            }
        }
        return '<tr><th>&nbsp;</th>'
                .($this->tableInfo['haveObjRow'] ? '<th>&nbsp;</th>' : '')
                .'<th>'.\implode('</th><th scope="col">', $headers).'</th>'
            .'</tr>'."\n";
    }

    /**
     * Returns table row
     *
     * @param mixed $row    should be array or abstraction
     * @param array $keys   column keys
     * @param array $rowKey row key
     *
     * @return string
     */
    protected function buildTableRow($row, $keys, $rowKey)
    {
        $str = '';
        $values = $this->debug->methodTable->keyValues($row, $keys, $objInfo);
        $parsed = $this->debug->utilities->parseTag($this->dump($rowKey));
        $str .= '<tr>';
        $str .= $this->debug->utilities->buildTag(
            'th',
            array(
                'class' => 't_key text-right '.$parsed['attribs']['class'],
                'scope' => 'row',
            ),
            $parsed['innerhtml']
        );
        if ($objInfo['row']) {
            $str .= $this->markupClassname($objInfo['row']['className'], 'td', array(
                'title' => $objInfo['row']['phpDoc']['summary'] ?: null,
            ));
            $this->tableInfo['haveObjRow'] = true;
        } else {
            $str .= '<td class="t_classname"></td>';
        }
        foreach ($values as $v) {
            $str .= $this->dump($v, null, true, 'td');
        }
        $str .= '</tr>'."\n";
        $str = \str_replace(' title=""', '', $str);
        foreach ($objInfo['cols'] as $k2 => $classname) {
            if ($this->tableInfo['colClasses'][$k2] === false) {
                // column values not of the same type
                continue;
            }
            if ($this->tableInfo['colClasses'][$k2] === null) {
                $this->tableInfo['colClasses'][$k2] = $classname;
            }
            if ($this->tableInfo['colClasses'][$k2] !== $classname) {
                $this->tableInfo['colClasses'][$k2] = false;
            }
        }
        return $str;
    }

    /**
     * Dump array as html
     *
     * @param array $array array
     * @param array $path  {@internal}
     *
     * @return string html
     */
    protected function dumpArray($array, $path = array())
    {
        if (empty($array)) {
            $html = '<span class="t_keyword">array</span>'
                .'<span class="t_punct">()</span>';
        } else {
            $displayKeys = $this->debug->getCfg('output.displayListKeys') || !$this->debug->utilities->isList($array);
            $html = '<span class="t_keyword">array</span>'
                .'<span class="t_punct">(</span>'."\n";
            if ($displayKeys) {
                $html .= '<span class="array-inner">'."\n";
                foreach ($array as $key => $val) {
                    $html .= "\t".'<span class="key-value">'
                            .'<span class="t_key'.(\is_int($key) ? ' t_int' : '').'">'
                                .$this->dump($key, $path, true, false) // don't wrap it
                            .'</span> '
                            .'<span class="t_operator">=&gt;</span> '
                            .$this->dump($val)
                        .'</span>'."\n";
                }
                $html .= '</span>';
            } else {
                // display as list
                $html .= '<ul class="array-inner list-unstyled">'."\n";
                foreach ($array as $val) {
                    $html .= $this->dump($val, $path, true, 'li');
                }
                $html .= '</ul>';
            }
            $html .= '<span class="t_punct">)</span>';
        }
        return $html;
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
        return $val ? 'true' : 'false';
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
        return '<span class="t_type">callable</span> '
            .$this->markupClassname($abs['values'][0].'::'.$abs['values'][1]);
    }

    /**
     * Dump float value
     *
     * @param integer $val float value
     *
     * @return float
     */
    protected function dumpFloat($val)
    {
        $date = $this->checkTimestamp($val);
        if ($date) {
            $this->wrapAttribs['class'][] = 'timestamp';
            $this->wrapAttribs['title'] = $date;
        }
        return $val;
    }

    /**
     * Dump integer value
     *
     * @param integer $val integer value
     *
     * @return integer
     */
    protected function dumpInt($val)
    {
        return $this->dumpFloat($val);
    }

    /**
     * Dump null value
     *
     * @return string
     */
    protected function dumpNull()
    {
        return 'null';
    }

    /**
     * Dump object as html
     *
     * @param array $abs  object abstraction
     * @param array $path {@internal}
     *
     * @return string
     */
    protected function dumpObject($abs, $path = array())
    {
        /*
            Were we debugged from inside or outside of the object?
        */
        $dump = $this->object->dump($abs);
        $this->wrapAttribs['data-accessible'] = $abs['scopeClass'] == $abs['className']
            ? 'private'
            : 'public';
        return $dump;
    }

    /**
     * Dump recursion (array recursion)
     *
     * @return string
     */
    protected function dumpRecursion()
    {
        return '<span class="t_keyword">array</span> <span class="t_recursion">*RECURSION*</span>';
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
        if (\is_numeric($val)) {
            $date = $this->checkTimestamp($val);
            if ($date) {
                $this->wrapAttribs['class'][] = 'timestamp';
                $this->wrapAttribs['title'] = $date;
            }
        } else {
            if ($this->sanitize) {
                $val = $this->debug->utf8->dump($val, true, true);
                $val = $this->visualWhiteSpace($val);
            } else {
                $this->wrapAttribs['class'][] = 'no-pseudo';
                $val = $this->debug->utf8->dump($val, true, false);
            }
        }
        return $val;
    }

    /**
     * Dump undefined
     *
     * @return string
     */
    protected function dumpUndefined()
    {
        return '';
    }

    /**
     * Display channel checkboxes
     *
     * @return string
     */
    protected function getChannelToggles()
    {
        if (\count($this->channels) < 2) {
            return '';
        }
        \sort($this->channels);
        $rootKey = \array_search($this->channelNameRoot, $this->channels);
        if ($rootKey !== false) {
            unset($this->channels[$rootKey]);
            \array_unshift($this->channels, $this->channelName);
        }
        $checkboxes = '';
        foreach ($this->channels as $channel) {
            $checkboxes .= '<li><label>'
                .$this->debug->utilities->buildTag(
                    'input',
                    array(
                        'checked' => true,
                        'data-is-root' => $channel == $this->channelNameRoot,
                        'data-toggle' => 'channel',
                        'type' => 'checkbox',
                        'value' => $channel,
                    )
                )
                .' '.\htmlspecialchars($channel)
                .'</label></li>'."\n";
        }
        $this->channels = array();
        return '<fieldset class="channels" style="display:none;">'."\n"
                .'<legend>Channels</legend>'."\n"
                .'<ul class="list-unstyled">'."\n"
                .$checkboxes
                .'</ul>'."\n"
            .'</fieldset>'."\n";
    }

    /**
     * Getter for this->object
     *
     * @return HtmlObject
     */
    protected function getObject()
    {
        $this->object = new HtmlObject($this->debug);
        return $this->object;
    }

    /**
     * Handle alert method
     *
     * @param array $args arguments
     * @param array $meta meta info
     *
     * @return array array($method, $args)
     */
    protected function methodAlert($args, $meta)
    {
        $attribs = array(
            'class' => 'alert alert-'.$meta['class'],
            'data-channel' => $meta['channel'],
            'role' => 'alert',
        );
        if ($meta['dismissible']) {
            $attribs['class'] .= ' alert-dismissible';
            $args[0] = '<button type="button" class="close" data-dismiss="alert" aria-label="Close">'
                .'<span aria-hidden="true">&times;</span>'
                .'</button>'
                .$args[0];
        }
        return $this->debug->utilities->buildTag('div', $attribs, $args[0]);
    }

    /**
     * process alerts
     *
     * @return string
     */
    protected function processAlerts()
    {
        $errorSummary = $this->errorSummary->build($this->debug->internal->errorStats());
        if ($errorSummary) {
            \array_unshift($this->data['alerts'], array(
                'alert',
                array($errorSummary),
                array(
                    'class' => 'danger error-summary',
                    'dismissible' => false,
                )
            ));
        }
        return parent::processAlerts();
    }

    /**
     * Cooerce value to string
     *
     * @param mixed $val value
     *
     * @return string
     */
    protected function substitutionAsString($val)
    {
        $type = $this->debug->abstracter->getType($val);
        if ($type == 'string') {
            $val = $this->dump($val, array(), true, false);
        } elseif ($type == 'array') {
            $count = \count($val);
            $val = '<span class="t_keyword">array</span>'
                .'<span class="t_punct">(</span>'.$count.'<span class="t_punct">)</span>';
        } elseif ($type == 'object') {
            $val = $this->markupClassname($val['className']);
        } else {
            $val = $this->dump($val);
        }
        return $val;
    }

    /**
     * Add whitespace markup
     *
     * @param string $str string which to add whitespace html markup
     *
     * @return string
     */
    protected function visualWhiteSpace($str)
    {
        // display \r, \n, & \t
        $str = \preg_replace_callback('/(\r\n|\r|\n)/', array($this, 'visualWhiteSpaceCallback'), $str);
        $str = \preg_replace('#(<br />)?\n$#', '', $str);
        $str = \str_replace("\t", '<span class="ws_t">'."\t".'</span>', $str);
        return $str;
    }

    /**
     * Adds whitespace markup
     *
     * @param array $matches passed from preg_replace_callback
     *
     * @return string
     */
    protected function visualWhiteSpaceCallback($matches)
    {
        $strBr = $this->debug->getCfg('addBR') ? '<br />' : '';
        $search = array("\r","\n");
        $replace = array('<span class="ws_r"></span>','<span class="ws_n"></span>'.$strBr."\n");
        return \str_replace($search, $replace, $matches[1]);
    }
}
