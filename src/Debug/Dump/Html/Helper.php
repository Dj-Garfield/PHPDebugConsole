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

namespace bdk\Debug\Dump\Html;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Dump\Html as Dumper;

/**
 * Html dump helper methods
 */
class Helper
{
    protected $debug;
    protected $dumper;
    protected $types = array(
        // scalar
        Abstracter::TYPE_BOOL, Abstracter::TYPE_FLOAT, Abstracter::TYPE_INT, Abstracter::TYPE_STRING,
        // compound
        Abstracter::TYPE_ARRAY, Abstracter::TYPE_CALLABLE, Abstracter::TYPE_OBJECT, 'iterable',
        // "special"
        Abstracter::TYPE_NULL, Abstracter::TYPE_RESOURCE,
        // other
        '$this', 'false', 'mixed', 'static', 'self', 'true', 'void',
    );

    /**
     * Constructor
     *
     * @param Dumper $dumper Dump\Html instance
     */
    public function __construct(Dumper $dumper)
    {
        $this->debug = $dumper->debug;
        $this->dumper = $dumper;
    }

    /**
     * Convert all arguments to html and join them together.
     *
     * @param array $args arguments
     * @param array $meta meta values
     *
     * @return string html
     */
    public function buildArgString($args, $meta = array())
    {
        if (\count($args) === 0) {
            return '';
        }
        $glueDefault = ', ';
        $glueAfterFirst = true;
        if (\is_string($args[0])) {
            if (\preg_match('/[=:] ?$/', $args[0])) {
                // first arg ends with "=" or ":"
                $glueAfterFirst = false;
                $args[0] = \rtrim($args[0]) . ' ';
            } elseif (\count($args) === 2) {
                $glueDefault = ' = ';
            }
        }
        $glue = $meta['glue'] ?: $glueDefault;
        $args = $this->buildArgStringArgs($args, $meta);
        return $glueAfterFirst
            ? \implode($glue, $args)
            : $args[0] . \implode($glue, \array_slice($args, 1));
    }

    /**
     * build php code snippet / context
     *
     * @param string[] $lines   lines of code
     * @param int      $lineNum line number to highlight
     *
     * @return string
     */
    public function buildContext($lines, $lineNum)
    {
        return $this->debug->html->buildTag(
            'pre',
            array(
                'class' => 'highlight line-numbers',
                'data-line' => $lineNum,
                'data-start' => \key($lines),
            ),
            '<code class="language-php">' . \htmlspecialchars(\implode($lines)) . '</code>'
        );
    }

    /**
     * Markup type-hint / type declaration
     *
     * @param string $type    type declaration
     * @param array  $attribs (optional) additional html attributes
     *
     * @return string
     */
    public function markupType($type, $attribs = array())
    {
        $types = \preg_split('/\s*\|\s*/', (string) $type);
        foreach ($types as $i => $type) {
            $types[$i] = $this->markupTypePart($type);
        }
        $types = \implode('<span class="t_punct">|</span>', $types);
        $attribs = \array_filter($attribs);
        if ($attribs) {
            $type = $this->debug->html->buildtag('span', $attribs, $types);
        }
        return $types;
    }

    /**
     * Insert a row containing code snip & arguments after the given row
     *
     * @param string $html    <tr>...</tr>
     * @param array  $row     Row values
     * @param array  $rowInfo Row info / meta
     * @param int    $index   Row index
     *
     * @return string
     */
    public function tableAddContextRow($html, $row, $rowInfo, $index)
    {
        if (!$rowInfo['context']) {
            return $html;
        }
        $html = \str_replace('<tr>', '<tr' . ($index === 0 ? ' class="expanded"' : '') . ' data-toggle="next">', $html);
        $html .= '<tr class="context" ' . ($index === 0 ? 'style="display:table-row;"' : '' ) . '>'
            . '<td colspan="4">'
                . $this->buildContext($rowInfo['context'], $row['line'])
                . '{{arguments}}'
            . '</td>' . "\n"
            . '</tr>' . "\n";
        $crateRawWas = $this->dumper->crateRaw;
        $this->dumper->crateRaw = true;
        $args = $rowInfo['args']
            ? '<hr />Arguments = ' . $this->dumper->valDumper->dump($rowInfo['args'])
            : '';
        $this->dumper->crateRaw = $crateRawWas;
        return \str_replace('{{arguments}}', $args, $html);
    }

    /**
     * Format trace table's function column
     *
     * @param string $html <tr>...</tr>
     * @param array  $row  row values
     *
     * @return string
     */
    public function tableMarkupFunction($html, $row)
    {
        if (isset($row['function'])) {
            $replace = $this->dumper->valDumper->markupIdentifier($row['function'], true, 'span', array(), true);
            $replace = '<td class="col-function no-quotes t_string">' . $replace . '</td>';
            $html = \str_replace(
                '<td class="t_string">' . \htmlspecialchars($row['function']) . '</td>',
                $replace,
                $html
            );
        }
        return $html;
    }

    /**
     * Return array of dumped arguments
     *
     * @param array $args arguments
     * @param array $meta meta values
     *
     * @return array
     */
    private function buildArgStringArgs($args, $meta)
    {
        foreach ($args as $i => $v) {
            list($type, $typeMore) = $this->debug->abstracter->getType($v);
            $typeMore2 = $typeMore === Abstracter::TYPE_ABSTRACTION
                ? $v['typeMore']
                : $typeMore;
            $args[$i] = $this->dumper->valDumper->dump($v, array(
                'addQuotes' => $i !== 0 || $typeMore2 === Abstracter::TYPE_STRING_NUMERIC,
                'sanitize' => $i === 0
                    ? $meta['sanitizeFirst']
                    : $meta['sanitize'],
                'type' => $type,
                'typeMore' => $typeMore,
                'visualWhiteSpace' => $i !== 0,
            ));
        }
        return $args;
    }

    /**
     * Markup a single type-hint / type decloration
     *
     * @param string $type type declaration
     *
     * @return string
     */
    private function markupTypePart($type)
    {
        $isArray = false;
        if (\substr($type, -2) === '[]') {
            $isArray = true;
            $type = \substr($type, 0, -2);
        }
        if (\in_array($type, $this->types) === false) {
            $type = $this->dumper->valDumper->markupIdentifier($type);
        }
        if ($isArray) {
            $type .= '<span class="t_punct">[]</span>';
        }
        return '<span class="t_type">' . $type . '</span>';
    }
}
