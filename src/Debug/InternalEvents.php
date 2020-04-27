<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2020 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\LogEntry;
use bdk\Debug\Plugin\Highlight;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use bdk\PubSub\SubscriberInterface;

/**
 *
 */
class InternalEvents implements SubscriberInterface
{

    private $debug;

    private $highlightAdded = false;
    private $inShutdown = false;

    /**
     * duplicate/store frequently used cfg vals
     *
     * @var array
     */
    private $cfg = array(
        'logResponse' => false,
    );

    /**
     * Constructor
     *
     * @param Debug $debug debug instance
     */
    public function __construct(Debug $debug)
    {
        $this->debug = $debug;
        $this->debug->eventManager->addSubscriberInterface($this);
        if ($debug->parentInstance) {
            return;
        }
        $this->debug->errorHandler->eventManager->subscribe('errorHandler.error', array(function () {
            // this closure lazy-loads the subscriber object
            return $this->debug->errorEmailer;
        }, 'onErrorHighPri'), PHP_INT_MAX);
        $this->debug->errorHandler->eventManager->subscribe('errorHandler.error', array(function () {
            // this closure lazy-loads the subscriber object
            return $this->debug->errorEmailer;
        }, 'onErrorLowPri'), PHP_INT_MAX * -1);
        /*
            Initial setCfg has already occured... so we missed the initial debug.config event
            manually call onConfig here
        */
        $this->onConfig(new Event(
            $this->debug,
            array(
                'debug' => $this->debug->getCfg(null, Debug::CONFIG_DEBUG),
            )
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscriptions()
    {
        if ($this->debug->parentInstance) {
            // we are a child channel
            return array(
                'debug.output' => array(
                    array('onOutput', 1),
                    array('onOutputHeaders', -1),
                ),
                'debug.config' => 'onConfig',
            );
        }
        /*
            OnShutDownHigh2 subscribes to 'debug.log' (onDebugLogShutdown)
              so... if any log entry is added in php's shutdown phase, we'll have a
              "php.shutdown" log entry
        */
        return array(
            'debug.config' => 'onConfig',
            'debug.dumpCustom' => 'onDumpCustom',
            'debug.log' => array('onLog', PHP_INT_MAX),
            'debug.output' => array(
                array('onOutput', 1),
                array('onOutputHeaders', -1),
            ),
            'debug.prettify' => array('onPrettify', -1),
            'debug.streamWrap' => 'onStreamWrap',
            'errorHandler.error' => 'onError',
            'php.shutdown' => array(
                array('onShutdownHigh', PHP_INT_MAX),
                array('onShutdownHigh2', PHP_INT_MAX - 10),
                array('onShutdownLow', PHP_INT_MAX * -1)
            ),
        );
    }

    /**
     * debug.config subscriber
     *
     * @param Event $event event instance
     *
     * @return void
     */
    public function onConfig(Event $event)
    {
        $cfg = $event->getValues();
        if (empty($cfg['debug'])) {
            // no debug config values have changed
            return;
        }
        $cfg = $cfg['debug'];
        $valActions = array(
            'logResponse' => array($this, 'onCfgLogResponse'),
            'onLog' => array($this, 'onCfgOnLog'),
            'onMiddleware' => array($this, 'onCfgOnMiddleware'),
            'onOutput' => array($this, 'onCfgOnOutput'),
        );
        foreach ($valActions as $key => $callable) {
            if (isset($cfg[$key])) {
                $callable($cfg[$key], $event);
            }
        }
    }

    /**
     * Listen for a log entry occuring after php.shutdown...
     *
     * @return void
     */
    public function onDebugLogShutdown()
    {
        $this->debug->eventManager->unsubscribe('debug.log', array($this, __FUNCTION__));
        $this->debug->info('php.shutdown', $this->debug->meta(array(
            'attribs' => array(
                'class' => 'php-shutdown',
            ),
            'icon' => 'fa fa-power-off',
        )));
    }

    /**
     * debug.dumpCustom subscriber
     *
     * @param Event $event event instance
     *
     * @return void
     */
    public function onDumpCustom(Event $event)
    {
        $abs = $event->getSubject();
        if ($abs['return']) {
            // return already defined..   prev subscriber should have stopped propagation
            return;
        }
        $event['return'] = \print_r($abs->getValues(), true);
        $event['typeMore'] = 't_string';
    }

    /**
     * errorHandler.error event subscriber
     * adds error to console as error or warn
     *
     * @param Error $error error/event object
     *
     * @return void
     */
    public function onError(Error $error)
    {
        if ($this->debug->getCfg('collect', Debug::CONFIG_DEBUG)) {
            $errLoc = $error['file'] . ' (line ' . $error['line'] . ')';
            $meta = $this->debug->meta(array(
                'backtrace' => $error['backtrace'],
                'errorCat' => $error['category'],
                'errorHash' => $error['hash'],
                'errorType' => $error['type'],
                'file' => $error['file'],
                'isSuppressed' => $error['isSuppressed'], // set via event subscriber vs "@"" code prefix
                'line' => $error['line'],
                'sanitize' => $error['isHtml'] === false,
            ));
            $method = $error['type'] & $this->debug->getCfg('errorMask', Debug::CONFIG_DEBUG)
                ? 'error'
                : 'warn';
            /*
                specify rootInstance as there's nothing to prevent calling Internal::onError() dirrectly (from aanother instance)
            */
            $this->debug->rootInstance->getChannel('phpError')->{$method}(
                $error['typeStr'] . ':',
                $error['message'],
                $errLoc,
                $meta
            );
            $error['continueToNormal'] = false; // no need for PHP to log the error, we've captured it here
            $error['inConsole'] = true;
            // Prevent ErrorHandler\ErrorEmailer from sending email.
            // Since we're collecting log info, we send email on shutdown
            $error['email'] = false;
        } elseif ($this->debug->getCfg('output', Debug::CONFIG_DEBUG)) {
            $error['email'] = false;
            $error['inConsole'] = false;
        } else {
            $error['inConsole'] = false;
        }
    }

    /**
     * debug.log subscriber
     *
     * @param LogEntry $logEntry log entry instance
     *
     * @return void
     */
    public function onLog(LogEntry $logEntry)
    {
        if ($logEntry->getMeta('redact')) {
            $debug = $logEntry->getSubject();
            $logEntry['args'] = $debug->redact($logEntry['args']);
        }
    }

    /**
     * debug.output subscriber
     *
     * @param Event $event debug.output event object
     *
     * @return void
     */
    public function onOutput(Event $event)
    {
        if ($event['isTarget']) {
            /*
                All channels share the same data.
                We only need to do this via the channel that called output
            */
            $this->onOutputCleanup();
        }
        if (!$this->debug->parentInstance) {
            $this->onOutputLogRuntime();
        }
    }

    /**
     * debug.output subscriber
     *
     * Merge event headers into data['headers'] or output them
     *
     * @param Event $event debug.output event object
     *
     * @return void
     */
    public function onOutputHeaders(Event $event)
    {
        $headers = $event['headers'];
        $outputHeaders = $event->getSubject()->getCfg('outputHeaders', Debug::CONFIG_DEBUG);
        if (!$outputHeaders || !$headers) {
            $event->getSubject()->setData('headers', \array_merge(
                $event->getSubject()->getData('headers'),
                $headers
            ));
        } elseif (\headers_sent($file, $line)) {
            \trigger_error('PHPDebugConsole: headers already sent: ' . $file . ', line ' . $line, E_USER_NOTICE);
        } else {
            foreach ($headers as $nameVal) {
                \header($nameVal[0] . ': ' . $nameVal[1]);
            }
        }
    }

    /**
     * Prettify a string if known content-type
     *
     * @param Event $event debug.prettyify event object
     *
     * @return void
     */
    public function onPrettify(Event $event)
    {
        if (\preg_match('#\b(html|json|xml)\b#', $event['contentType'], $matches)) {
            $string = $event['value'];
            $type = $matches[1];
            $lang = $type;
            if ($type === 'html') {
                $lang = 'markup';
            } elseif ($type === 'json') {
                $string = $this->debug->utility->prettyJson($string);
            } elseif ($type === 'xml') {
                $string = $this->debug->utility->prettyXml($string);
            }
            if (!$this->highlightAdded) {
                $this->debug->addPlugin(new Highlight());
                $this->highlightAdded = true;
            }
            $event['value'] = new Abstraction(array(
                'type' => 'string',
                'attribs' => array(
                    'class' => 'highlight language-' . $lang,
                ),
                'addQuotes' => false,
                'visualWhiteSpace' => false,
                'value' => $string,
            ));
            $event->stopPropagation();
        }
    }

    /**
     * If profiling, inject `declare(ticks=1)`
     *
     * @param Event $event debug.streamWrap event object
     *
     * @return void
     */
    public function onStreamWrap(Event $event)
    {
        $declare = 'declare(ticks=1);';
        $event['content'] = \preg_replace(
            '/^(<\?php)\s*$/m',
            '$0 ' . $declare,
            $event['content'],
            1
        );
    }

    /**
     * php.shutdown subscriber (high priority)
     *
     * @return void
     */
    public function onShutdownHigh()
    {
        $this->closeOpenGroups();
        $this->inShutdown = true;
    }

    /**
     * php.shutdown subscriber (not-so-high priority).. come after other internal...
     *
     * @return void
     */
    public function onShutdownHigh2()
    {
        $this->debug->eventManager->subscribe('debug.log', array($this, 'onDebugLogShutdown'));
    }

    /**
     * php.shutdown subscriber (low priority)
     * Email Log if emailLog is 'always' or 'onError'
     * output log if not already output
     *
     * @return void
     */
    public function onShutdownLow()
    {
        $this->debug->eventManager->unsubscribe('debug.log', array($this, 'onDebugLogShutdown'));
        if ($this->testEmailLog()) {
            $this->runtimeVals();
            $this->debug->routeEmail->processLogEntries(new Event($this->debug));
        }
        if (!$this->debug->getData('outputSent')) {
            echo $this->debug->output();
        }
    }

    /**
     * Close any unclosed groups
     *
     * We may have forgotten to end a group or the script may have exited
     *
     * @return void
     */
    private function closeOpenGroups()
    {
        if ($this->inShutdown) {
            // we already closed
            return;
        }
        $groupPriorityStack = \array_merge(array('main'), $this->debug->getData('groupPriorityStack'));
        $groupStacks = $this->debug->getData('groupStacks');
        while ($groupPriorityStack) {
            $priority = \array_pop($groupPriorityStack);
            foreach ($groupStacks[$priority] as $info) {
                $info['channel']->groupEnd();
            }
            if (\is_int($priority)) {
                // close the summary
                $this->debug->groupEnd();
            }
        }
    }

    /**
     * Handle "logResponse" config update
     *
     * @param mixed $val config value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgLogResponse($val)
    {
        if ($val === 'auto') {
            $serverParams = \array_merge(array(
                'HTTP_ACCEPT' => null,
                'HTTP_SOAPACTION' => null,
                'HTTP_USER_AGENT' => null,
            ), $this->debug->request->getServerParams());
            $val = \count(
                \array_filter(array(
                    \strpos($this->debug->utility->getInterface(), 'http') !== false,
                    $serverParams['HTTP_SOAPACTION'],
                    \stripos($serverParams['HTTP_USER_AGENT'], 'curl') !== false,
                ))
            ) > 0;
        }
        if ($val) {
            if (!$this->cfg['logResponse']) {
                \ob_start();
            }
        } elseif ($this->cfg['logResponse']) {
            \ob_end_flush();
        }
        $this->cfg['logResponse'] = $val;
    }

    /**
     * Handle "onLog" config update
     *
     * @param mixed $val config value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgOnLog($val)
    {
        /*
            Replace - not append - subscriber set via setCfg
        */
        $prev = $this->debug->getCfg('onLog', Debug::CONFIG_DEBUG);
        if ($prev) {
            $this->debug->eventManager->unsubscribe('debug.log', $prev);
        }
        $this->debug->eventManager->subscribe('debug.log', $val);
    }

    /**
     * Handle "onOutput" config update
     *
     * @param mixed $val config value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgOnOutput($val)
    {
        /*
            Replace - not append - subscriber set via setCfg
        */
        $prev = $this->debug->getCfg('onOutput', Debug::CONFIG_DEBUG);
        if ($prev) {
            $this->debug->eventManager->unsubscribe('debug.output', $prev);
        }
        $this->debug->eventManager->subscribe('debug.output', $val);
    }

    /**
     * Handle "onMiddleware" config update
     *
     * @param mixed $val config value
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgOnMiddleware($val)
    {
        /*
            Replace - not append - subscriber set via setCfg
        */
        $prev = $this->debug->getCfg('onMiddleware', Debug::CONFIG_DEBUG);
        if ($prev) {
            $this->debug->eventManager->unsubscribe('debug.middleware', $prev);
        }
        $this->debug->eventManager->subscribe('debug.middleware', $val);
    }

    /**
     * "cleanup"
     *    close open groups
     *    remove "hide-if-empty" groups
     *    uncollapse errors
     *
     * @return void
     */
    private function onOutputCleanup()
    {
        $this->closeOpenGroups();
        $data = $this->debug->getData();
        $data['headers'] = array();
        $this->removeHideIfEmptyGroups($data['log']);
        $this->uncollapseErrors($data['log']);
        foreach ($data['logSummary'] as &$log) {
            $this->removeHideIfEmptyGroups($log);
            $this->uncollapseErrors($log);
        }
        $this->debug->setData($data);
    }

    /**
     * Log our runtime info in a summary group
     *
     * As we're only subscribed to root debug instance's debug.output event, this info
     *   will not be output for any sub-channels output directly
     *
     * @return void
     */
    private function onOutputLogRuntime()
    {
        if (!$this->debug->getCfg('logRuntime', Debug::CONFIG_DEBUG)) {
            return;
        }
        $vals = $this->runtimeVals();
        $route = $this->debug->getCfg('route');
        $isRouteHtml = $route && \get_class($route) === 'bdk\\Debug\\Route\\Html';
        $this->debug->groupSummary(1);
        $this->debug->info('Built In ' . $this->debug->utility->formatDuration($vals['runtime']));
        $this->debug->info(
            'Peak Memory Usage'
                . ($isRouteHtml
                    ? ' <span title="Includes debug overhead">?&#x20dd;</span>'
                    : '')
                . ': '
                . $this->debug->utility->getBytes($vals['memoryPeakUsage']) . ' / '
                . $this->debug->utility->getBytes($vals['memoryLimit']),
            $this->debug->meta('sanitize', false)
        );
        $this->debug->groupEnd();
    }

    /**
     * Remove empty groups with 'hideIfEmpty' meta value
     *
     * @param array $log log or summary
     *
     * @return void
     */
    private function removeHideIfEmptyGroups(&$log)
    {
        $groupStack = array();
        $groupStackCount = 0;
        $removed = false;
        for ($i = 0, $count = \count($log); $i < $count; $i++) {
            $logEntry = $log[$i];
            $method = $logEntry['method'];
            /*
                pushing/popping to/from groupStack led to unexplicable warning:
                "Cannot add element to the array as the next element is already occupied"
            */
            if (\in_array($method, array('group', 'groupCollapsed'))) {
                $groupStack[$groupStackCount] = array(
                    'i' => $i,
                    'meta' => $logEntry['meta'],
                    'hasEntries' => false,
                );
                $groupStackCount++;
            } elseif ($groupStackCount) {
                if ($method === 'groupEnd') {
                    $groupStackCount--;
                    $group = $groupStack[$groupStackCount];
                    if (!$group['hasEntries'] && !empty($group['meta']['hideIfEmpty'])) {
                        unset($log[$group['i']]);   // remove open entry
                        unset($log[$i]);            // remove end entry
                        $removed = true;
                    }
                    continue;
                }
                $groupStack[$groupStackCount - 1]['hasEntries'] = true;
            }
        }
        if ($removed) {
            $log = \array_values($log);
        }
    }

    /**
     * Get/store values such as runtime & peak memory usage
     *
     * @return array
     */
    private function runtimeVals()
    {
        $vals = $this->debug->getData('runtime');
        if (!$vals) {
            $vals = array(
                'memoryPeakUsage' => \memory_get_peak_usage(true),
                'memoryLimit' => $this->debug->utility->memoryLimit(),
                'runtime' => $this->debug->timeEnd('debugInit', $this->debug->meta('silent')),
            );
            $this->debug->setData('runtime', $vals);
        }
        return $vals;
    }

    /**
     * Test if conditions are met to email the log
     *
     * @return bool
     */
    private function testEmailLog()
    {
        if (!$this->debug->getCfg('emailTo', Debug::CONFIG_DEBUG)) {
            return false;
        }
        if ($this->debug->getCfg('output', Debug::CONFIG_DEBUG)) {
            // don't email log if we're outputing it
            return false;
        }
        if (!$this->debug->hasLog()) {
            return false;
        }
        $emailLog = $this->debug->getCfg('emailLog', Debug::CONFIG_DEBUG);
        if (\in_array($emailLog, array(true, 'always'), true)) {
            return true;
        }
        if ($emailLog === 'onError') {
            // see if we handled any unsupressed errors of types specified with emailMask
            $errors = $this->debug->errorHandler->get('errors');
            $emailMask = $this->debug->errorEmailer->getCfg('emailMask');
            $emailableErrors = \array_filter($errors, function ($error) use ($emailMask) {
                return !$error['isSuppressed'] && ($error['type'] & $emailMask);
            });
            return !empty($emailableErrors);
        }
        return false;
    }

    /**
     * Uncollapse groups containing errors.
     *
     * @param array $log log or summary
     *
     * @return void
     */
    private function uncollapseErrors(&$log)
    {
        $groupStack = array();
        for ($i = 0, $count = \count($log); $i < $count; $i++) {
            $method = $log[$i]['method'];
            if (\in_array($method, array('group', 'groupCollapsed'))) {
                $groupStack[] = $i;
            } elseif ($method === 'groupEnd') {
                \array_pop($groupStack);
            } elseif (\in_array($method, array('error', 'warn'))) {
                foreach ($groupStack as $i2) {
                    $log[$i2]['method'] = 'group';
                }
            }
        }
    }
}