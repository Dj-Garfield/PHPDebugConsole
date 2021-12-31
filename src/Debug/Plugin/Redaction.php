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

namespace bdk\Debug\Plugin;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\LogEntry;
use bdk\PubSub\Event;
use bdk\PubSub\SubscriberInterface;

/**
 * Add additional public methods to debug instance
 */
class Redaction implements SubscriberInterface
{
    /**
     * duplicate/store frequently used cfg vals
     *
     * @var array
     */
    private $cfg = array(
        'redactKeys' => array(
            // key => regex of key
        ),
        'redactReplace' => null,
    );

    /**
     * {@inheritDoc}
     */
    public function getSubscriptions()
    {
        return array(
            Debug::EVENT_CONFIG => 'onConfig',
            Debug::EVENT_CUSTOM_METHOD => 'onCustomMethod',
        );
    }

    /**
     * Debug::EVENT_CONFIG subscriber
     *
     * @param Event $event Event instance
     *
     * @return void
     */
    public function onConfig(Event $event)
    {
        $configs = $event->getValues();
        if (empty($configs['debug'])) {
            // no debug config values have changed
            return;
        }
        $cfgDebug = $configs['debug'];
        $valActions = array(
            'redactKeys' => array($this, 'onCfgRedactKeys'),
            'redactReplace' => function ($val) {
                $this->cfg['redactReplace'] = $val;
                return $val;
            },
        );
        $valActions = \array_intersect_key($valActions, $cfgDebug);
        foreach ($valActions as $key => $callable) {
            /** @psalm-suppress TooManyArguments */
            $cfgDebug[$key] = $callable($cfgDebug[$key], $event);
        }
        $event['debug'] = \array_merge($event['debug'], $cfgDebug);
    }

    /**
     * Debug::EVENT_LOG event subscriber
     *
     * @param LogEntry $logEntry logEntry instance
     *
     * @return void
     */
    public function onCustomMethod(LogEntry $logEntry)
    {
        $method = $logEntry['method'];
        $methods = array(
            'redact',
        );
        if (!\in_array($method, $methods)) {
            return;
        }
        $logEntry['handled'] = true;
        $logEntry['return'] = \call_user_func_array(array($this, $method), $logEntry['args']);
        $logEntry->stopPropagation();
    }

    /**
     * Redact
     *
     * @param mixed $val value to scrub
     * @param mixed $key array key, or property name
     *
     * @return mixed
     */
    public function redact($val, $key = null)
    {
        if (\is_string($val)) {
            return $this->redactString($val, $key);
        }
        if ($val instanceof Abstraction) {
            return $this->redactAbstraction($val);
        }
        if (\is_array($val)) {
            return $this->redactArray($val);
        }
        return $val;
    }

    /**
     * Handle "redactKeys" config update
     *
     * @param mixed $val config value
     *
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgRedactKeys($val)
    {
        $keys = array();
        foreach ($val as $key) {
            $keys[$key] = $this->redactBuildRegex($key);
        }
        $this->cfg['redactKeys'] = $keys;
        return $val;
    }

    /**
     * Redact Abstraction
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return Abstraction
     */
    private function redactAbstraction(Abstraction $abs)
    {
        if ($abs['type'] === Abstracter::TYPE_OBJECT) {
            $abs['properties'] = $this->redact($abs['properties']);
            $abs['stringified'] = $this->redact($abs['stringified']);
            if (isset($abs['methods']['__toString']['returnValue'])) {
                $abs['methods']['__toString']['returnValue'] = $this->redact($abs['methods']['__toString']['returnValue']);
            }
            return $abs;
        }
        if ($abs['value']) {
            $abs['value'] = $this->redact($abs['value']);
        }
        if ($abs['valueDecoded']) {
            $abs['valueDecoded'] = $this->redact($abs['valueDecoded']);
        }
        return $abs;
    }

    /**
     * Redact array
     *
     * @param array $array array to redact
     *
     * @return Abstraction
     */
    private function redactArray($array)
    {
        foreach ($array as $k => $v) {
            $array[$k] = $this->redact($v, $k);
        }
        return $array;
    }

    /**
     * Build Regex that will search for key=val in string
     *
     * @param string $key key to redact
     *
     * @return string
     */
    private function redactBuildRegex($key)
    {
        return '#(?:'
            // xml
            . '<(?:\w+:)?' . $key . '\b.*?>\s*([^<]*?)\s*</(?:\w+:)?' . $key . '>'
            . '|'
            // json
            . \json_encode($key) . '\s*:\s*"([^"]*?)"'
            . '|'
            // url encoded
            . '\b' . $key . '=([^\s&]+\b)'
            . ')#i';
    }

    /**
     * Redact string or portions within
     *
     * @param string $val string to redact
     * @param string $key if array value: the key. if object property: the prop name
     *
     * @return string
     */
    private function redactString($val, $key = null)
    {
        if (\is_string($key) && \array_key_exists($key, $this->cfg['redactKeys'])) {
            return \call_user_func($this->cfg['redactReplace'], $val, $key);
        }
        foreach ($this->cfg['redactKeys'] as $key => $regex) {
            $val = \preg_replace_callback($regex, function ($matches) use ($key) {
                $matches = \array_filter($matches, 'strlen');
                $substr = \end($matches);
                $replacement = \call_user_func($this->cfg['redactReplace'], $substr, $key);
                return \str_replace($substr, $replacement, $matches[0]);
            }, $val);
        }
        return $val;
    }
}
