<?php
/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2017 Brad Kent
 * @version   v1.4.0
 *
 * @link http://www.github.com/bkdotcom/PHPDebugConsole
 * @link https://developer.mozilla.org/en-US/docs/Web/API/console
 */

namespace bdk\Debug;

/**
 * Methods that are internal to the debug class
 *
 * a) Don't want to clutter the debug class
 * b) avoiding a base class as would need to require the base or have
 *       and autoloader in place to bootstrap the debug class
 * c) a trait for code not meant to be "reusable" seems like an anti-pattern
 *       still have the bootstrap/autoload issue
 */
class Internal
{

    private $cfg;
    private $data;
    private $debug;
    private $error;     // store error object when logging an error

    /**
     * Constructor
     *
     * @param object $debug debug instance
     * @param array  $cfg   config
     * @param array  $data  data
     */
    public function __construct($debug, &$cfg, &$data)
    {
        $this->cfg = $cfg;
        $this->data = $data;
        $this->debug = $debug;
        $this->debug->eventManager->subscribe('debug.construct', array($this, 'onConstruct'), -1);
        $this->debug->eventManager->subscribe('debug.log', array($this, 'onLog'), -1);
        $this->debug->eventManager->subscribe('debug.output', array($this, 'onOutput'));
        $this->debug->errorHandler->eventManager->subscribe('errorHandler.error', array($this, 'onError'));
        register_shutdown_function(array($this, 'onShutdown'));
    }

    /**
     * Get calling line/file for error and warn
     *
     * @return array
     */
    public function getErrorCaller()
    {
        $meta = array();
        if ($this->error) {
            // no need to store originating file/line... it's part of error message
            $meta = array(
                'errorType' => $this->error['type'],
                'errorCat' => $this->error['category'],
            );
        } else {
            $backtrace = version_compare(PHP_VERSION, '5.4.0', '>=')
                ? debug_backtrace(0, 8)
                : debug_backtrace(false);   // don't provide object
            foreach ($backtrace as $frame) {
                if (in_array($frame['function'], array('call_user_func','call_user_func_array'))) {
                    continue;
                }
                if (isset($frame['file']) && strpos($frame['file'], __DIR__) !== 0) {
                    $meta = array(
                        'file' => $frame['file'],
                        'line' => $frame['line'],
                    );
                    break;
                }
            }
        }
        return $meta;
    }

    /**
     * Returns meta-data and removes it from the passed arguments
     *
     * @param array $args args to check
     *
     * @return array meta information
     */
    public static function getMetaArg(&$args)
    {
        $end = end($args);
        if (is_array($end) && ($key = array_search(\bdk\Debug::META, $end, true)) !== false) {
            array_pop($args);
            unset($end[$key]);
            return $end;
        }
        return array();
    }

    /**
     * Do we have log entries?
     *
     * @return boolean
     */
    public function haveLog()
    {
        $entryCountInitial = $this->debug->getData('entryCountInitial');
        $entryCountCurrent = $this->debug->getData('entryCount');
        return $entryCountCurrent > $entryCountInitial;
    }

    /**
     * debug.init subscriber
     *
     * @return void
     */
    public function onConstruct()
    {
        if ($this->debug->getCfg('logEnvInfo')) {
            $collectWas = $this->debug->setCfg('collect', true);
            $this->debug->group('environment');
            $this->debug->groupUncollapse();
            foreach ($this->debug->getCfg('logServerKeys') as $k) {
                if ($k == 'REQUEST_TIME') {
                    $this->debug->info($k, date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']));
                } elseif (isset($_SERVER[$k])) {
                    $this->debug->info($k, $_SERVER[$k]);
                }
            }
            $this->debug->info('PHP Version', PHP_VERSION);
            $this->debug->info('memory_limit', $this->debug->utilities->memoryLimit());
            $this->debug->info('session.cache_limiter', ini_get('session.cache_limiter'));
            if (!empty($_COOKIE)) {
                $this->debug->info('$_COOKIE', $_COOKIE);
            }
            if (!empty($_POST)) {
                $this->debug->info('$_POST', $_POST);
            }
            if (!empty($_FILES)) {
                $this->debug->info('$_FILES', $_FILES);
            }
            $this->debug->groupEnd();
            $this->debug->setCfg('collect', $collectWas);
        }
    }

    /**
     * errorHandler.error event subscriber
     * adds error to console as error or warn
     *
     * @param Event $error error/event object
     *
     * @return void
     */
    public function onError(Event $error)
    {
        if ($this->debug->getCfg('collect')) {
            /*
                temporarily store error so that we can easily determine error/warn
                 a) came via error handler
                 b) calling info
            */
            $this->error = $error;
            $errStr = $error['typeStr'].': '.$error['file'].' (line '.$error['line'].'): '.$error['message'];
            if ($error['type'] & $this->debug->getCfg('errorMask')) {
                $this->debug->error($errStr);
            } else {
                $this->debug->warn($errStr);
            }
            $error['errorLog'] = false; // no need to error_log()..  we've captured it here
            $error['inConsole'] = true;
            // Prevent errorEmailer from sending email.
            // Since we're collecting log info, we send email on shutdown
            $error['email'] = false;
            $this->error = null;
        } else {
            $error['inConsole'] = false;
        }
    }

    /**
     * errorHandler.error event subscriber
     * adds error to console as error or warn
     *
     * @param Event $event debug.log event
     *
     * @return void
     */
    public function onLog(Event $event)
    {
        $method = $event['method'];
        $args = $event['args'];
        $meta = $event['meta'];
        if ($method == 'groupUncollapse') {
            // don't append to log
            $event->stopPropagation();
            return;
        }
        $isSummaryBookend = $method == 'groupSummary' || !empty($meta['closesSummary']);
        if ($isSummaryBookend) {
            $event->stopPropagation();
        }
        if ($this->cfg['file']) {
            $this->appendLogFile($method, $meta, $args);
        }
    }

    /**
     * debug.output event subscriber
     *
     * @return void
     */
    public function onOutput()
    {
        $this->debug->groupSummary(1);
        $this->debug->info('Built In '.$this->debug->timeEnd('debugInit', true).' sec');
        $this->debug->info(
            'Peak Memory Usage'
                .($this->debug->getCfg('output/outputAs') == 'html'
                    ? ' <span title="Includes debug overhead">?&#x20dd;</span>'
                    : ''
                )
                .': '
                .$this->debug->utilities->getBytes(memory_get_peak_usage(true)).' / '
                .$this->debug->utilities->getBytes($this->debug->utilities->memoryLimit())
        );
        $this->debug->groupEnd();
    }

    /**
     * Email Log if emailLog is 'always' or 'onError'
     * output log if not already output
     *
     * @return void
     */
    public function onShutdown()
    {
        if ($this->haveLog() && !$this->debug->getCfg('output') && $this->debug->getCfg('emailTo')) {
            /*
                We have log data, it's not being output and we have an emailTo addr
            */
            $email = false;
            if ($this->debug->getCfg('emailLog') === 'always') {
                $email = true;
            } elseif ($this->debug->getCfg('emailLog') === 'onError') {
                $errors = $this->debug->errorHandler->get('errors');
                $emailMask = $this->debug->errorHandler->getCfg('emailMask');
                $unsuppressedErrors = array_filter($errors, function ($error) {
                    return !$error['suppressed'];
                });
                $emailableErrors = array_filter($errors, function ($error) use ($emailMask) {
                    return $error['type'] & $emailMask;
                });
                $email = $unsuppressedErrors && $emailableErrors;
            }
            if ($email) {
                $this->debug->output->emailLog();
            }
        }
        if (!$this->debug->getData('outputSent')) {
            echo $this->debug->output();
        }
        return;
    }

    /**
     * Uncollapse groups containing errors.
     *
     * @return void
     */
    public function uncollapseErrors()
    {
        $groupStack = array();
        for ($i = 0, $count = count($this->data['log']); $i < $count; $i++) {
            $method = $this->data['log'][$i][0];
            if (in_array($method, array('group', 'groupCollapsed'))) {
                $groupStack[] = $i;
            } elseif ($method == 'groupEnd') {
                array_pop($groupStack);
            } elseif (in_array($method, array('error', 'warn'))) {
                foreach ($groupStack as $i2) {
                    $this->data['log'][$i2][0] = 'group';
                }
            }
        }
    }

    /**
     * Appends log entry to $this->cfg['file']
     *
     * @param string $method method
     * @param array  $meta   meta info
     * @param array  $args   args
     *
     * @return boolean
     */
    protected function appendLogFile($method, $meta, $args)
    {
        $success = false;
        $fileHandle = $this->getFileHandle();
        if ($fileHandle) {
            $success = true;
            if ($args) {
                if ($meta) {
                    $args[] = $meta;
                }
                $str = $this->output->outputText->processEntry($method, $args, $this->data['groupDepthFile']);
                $wrote = fwrite($fileHandle, $str."\n");
                $success = $wrote !== false;
            }
            if (in_array($method, array('group','groupCollapsed'))) {
                $this->data['groupDepthFile']++;
            } elseif ($method == 'groupEnd' && $this->data['groupDepthFile'] > 0) {
                $this->data['groupDepthFile']--;
            }
        }
        return $success;
    }

    /**
     * Get logfile's file handle
     *
     * @return resource
     */
    private function getFileHandle()
    {
        if (!isset($this->data['fileHandle']) && !empty($this->cfg['file'])) {
            $fileExists = file_exists($this->cfg['file']);
            $this->data['fileHandle'] = fopen($this->cfg['file'], 'a');
            if ($this->data['fileHandle']) {
                fwrite($this->data['fileHandle'], '***** '.date('Y-m-d H:i:s').' *****'."\n");
                if (!$fileExists) {
                    chmod($this->cfg['file'], 0660);
                }
            } else {
                // failed to open file
                $this->cfg['file'] = null;
            }
        }
        return $this->data['fileHandle'];
    }
}
