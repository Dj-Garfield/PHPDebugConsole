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

namespace bdk;

use \bdk\Debug\PluginInterface;

/**
 * Web-browser/javascript like console class for PHP
 */
class Debug
{

    private static $instance;
    private static $publicMethods = array();
    protected $cfg = array();
    protected $data = array();
    protected $groupDepthRef;
    protected $logRef;
    protected $config;      // config class
    public $abstracter;
    public $errorHandler;
    public $internal;
    public $output;
    public $utf8;
    public $utilities;
    public $eventManager;

    const META = "\x00meta\x00";
    const VERSION = "1.4.0";

    /**
     * Constructor
     *
     * @param array  $cfg          config
     * @param object $errorHandler optional - uses \bdk\Debug\ErrorHandler if not passed
     * @param object $eventManager optional - uses \bdk\Debug\EventManager if not passed
     */
    public function __construct($cfg = array(), $errorHandler = null, $eventManager = null)
    {
        $this->cfg = array(
            'collect'   => false,
            'file'      => null,            // if a filepath, will receive log data
            'key'       => null,
            'output'    => false,           // should debug.output listeners output?
            // errorMask = errors that appear as "error" in debug console... all other errors are "warn"
            'errorMask' => E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR
                            | E_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR,
            'emailLog'  => false,   // Whether to email a debug log.  (requires 'collect' to also be true)
                                    //
                                    //   false:   email will not be sent
                                    //   true or 'onError':   email will be sent (if log is not output)
                                    //   'always':  email sent regardless of whether error occured or log output
            'emailTo'   => !empty($_SERVER['SERVER_ADMIN'])
                ? $_SERVER['SERVER_ADMIN']
                : null,
            'logEnvInfo' => true,
            'logServerKeys' => array('REQUEST_URI','REQUEST_TIME','HTTP_HOST','SERVER_NAME','SERVER_ADDR','REMOTE_ADDR'),
            'emailFunc' => 'mail',          // callable
            // 'onLog' => null,
        );
        spl_autoload_register(array($this, 'autoloader'));
        $this->utilities = new Debug\Utilities();
        $this->eventManager = $eventManager
            ? $eventManager
            : new Debug\EventManager();
        if ($errorHandler) {
            $this->errorHandler = $errorHandler;
        } elseif (Debug\ErrorHandler::getInstance()) {
            $this->errorHandler = Debug\ErrorHandler::getInstance();
        } else {
            $this->errorHandler = new Debug\ErrorHandler($this->eventManager);
        }
        $this->data = array(
            'alerts'        => array(),    // array of alerts.  alerts will be shown at top of output when possible
            'counts'        => array(), // count method
            'entryCountInitial' => 0,   // store number of log entries created during init
            'fileHandle'    => null,
            'groupDepth'    => 0,
            'groupDepthFile'=> 0,
            'groupDepthSummary' => 0,
            'log'           => array(),
            'logSummary'    => array(),
            'outputSent'    => false,
            'requestId'     => $this->utilities->requestId(),
            'timers' => array(      // timer method
                'labels' => array(
                    // label => array(accumulatedTime, lastStartedTime|null)
                    'debugInit' => array(
                        0,
                        isset($_SERVER['REQUEST_TIME_FLOAT']) // php 5.4
                            ? $_SERVER['REQUEST_TIME_FLOAT']
                            : microtime(true)
                    ),
                ),
                'stack' => array(),
            ),
        );
        // Initialize self::$instance if not set
        //    so that self::getInstance() will always return original instance
        //    as opposed the the last instance created with new Debug()
        if (!isset(self::$instance)) {
            self::$instance = $this;
        }
        $this->setPublicMethods();
        $this->logRef = &$this->data['log'];
        $this->groupDepthRef = &$this->data['groupDepth'];
        $this->abstracter = new Debug\Abstracter(array());
        $this->config = new Debug\Config($this, $this->cfg);
        $this->errorEmailer = new Debug\ErrorEmailer();
        $this->internal = new Debug\Internal($this, $this->data);
        $this->output = new Debug\Output($this, array());
        $this->utf8 = new Debug\Utf8();
        $this->config->setCfg($cfg);
        $this->eventManager->addListener('debug.init', array($this->internal, 'onInit'));
        $this->eventManager->addListener('debug.output', array($this->internal, 'onOutput'));
        $this->errorHandler->eventManager->addListener('errorHandler.error', array($this->errorEmailer, 'onErrorAddEmailData'), 1);
        $this->errorHandler->eventManager->addListener('errorHandler.error', array($this->errorEmailer, 'onErrorEmail'), -1);
        $this->errorHandler->eventManager->addListener('errorHandler.error', array($this->internal, 'onError'));
        $this->eventManager->dispatch('debug.init', $this);
        $this->data['entryCountInitial'] = count($this->data['log']);
        register_shutdown_function(array($this->internal, 'onShutdown'));
        return;
    }

    /**
     * Magic method to allow us to call instance methods statically
     *
     * prefix the method with an underscore ie
     *    \bdk\Debug::_log('logged via static method');
     *
     * @param string $methodName Inaccessible method name
     * @param array  $args       Arguments passed to method
     *
     * @return mixed
     */
    public static function __callStatic($methodName, $args)
    {
        $instance = self::getInstance();
        $methodName = ltrim($methodName, '_');
        if (in_array($methodName, self::$publicMethods)) {
            return call_user_func_array(array($instance, $methodName), $args);
        }
    }

    /*
        Debugging Methods
    */

    /**
     * Add an alert to top of log
     *
     * @param string  $message     message
     * @param string  $class       (danger), info, success, warning
     * @param boolean $dismissible (false)
     *
     * @return void
     */
    public function alert($message, $class = 'danger', $dismissible = false)
    {
        if ($this->cfg['collect']) {
            $this->appendLog('alert', array(
                'message' => $message,
                'class' => $class,
                'dismissible' => $dismissible,
            ));
        }
    }

    /**
     * Log a message and stack trace to console if first argument is false.
     *
     * Only appends log when assertation fails
     *
     * @return void
     */
    public function assert()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $test = array_shift($args);
            if (!$test) {
                $this->appendLog('assert', $args);
            }
        }
    }

    /**
     * Log the number of times this has been called with the given label.
     *
     * @param mixed $label label
     *
     * @return integer
     */
    public function count($label = null)
    {
        $return = 0;
        if ($this->cfg['collect']) {
            $args = array();
            if (isset($label)) {
                $args[] = $label;
            } else {
                // determine calling file & line
                $args[] = 'count';
                $backtrace = version_compare(PHP_VERSION, '5.4.0', '>=')
                    ? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)
                    : debug_backtrace(false);   // don't provide object
                $label = $backtrace[0]['file'].': '.$backtrace[0]['line'];
            }
            if (!isset($this->data['counts'][$label])) {
                $this->data['counts'][$label] = 1;
            } else {
                $this->data['counts'][$label]++;
            }
            $args[] = $this->data['counts'][$label];
            $this->appendLog('count', $args);
            $return = $this->data['counts'][$label];
        }
        return $return;
    }

    /**
     * Outputs an error message.
     *
     * @param mixed $label,... label
     *
     * @return void
     */
    public function error()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $this->appendLog('error', $args);
        }
    }

    /**
     * Creates a new inline group
     *
     * @return void
     */
    public function group()
    {
        $this->groupDepthRef++;
        if ($this->cfg['collect']) {
            $args = func_get_args();
            if (empty($args)) {
                // give a default label
                $args[] = 'group';
            }
            $this->appendLog('group', $args);
        }
    }

    /**
     * Creates a new inline group
     *
     * @return void
     */
    public function groupCollapsed()
    {
        $this->groupDepthRef++;
        if ($this->cfg['collect']) {
            $args = func_get_args();
            if (empty($args)) {
                // give a default label
                $args[] = 'group';
            }
            $this->appendLog('groupCollapsed', $args);
        }
    }

    /**
     * Close current group
     *
     * @return void
     */
    public function groupEnd()
    {
        $closingSummary = false;
        if ($this->data['groupDepthSummary'] > 0) {
            // currently in summaryGroup
            $this->data['groupDepthSummary']--;
            $closingSummary = $this->data['groupDepthSummary'] == 0;
        } elseif ($this->data['groupDepth'] > 0) {
            $this->data['groupDepth']--;
        }
        $errorCaller = $this->errorHandler->get('errorCaller');
        if ($errorCaller && isset($errorCaller['depth']) && $this->data['groupDepth'] < $errorCaller['depth']) {
            $this->errorHandler->setErrorCaller(null);
        }
        if ($this->cfg['collect']) {
            $this->appendLog('groupEnd', func_get_args());
        }
        if ($closingSummary) {
            $this->logRef = &$this->data['log'];
            $this->groupDepthRef = &$this->data['groupDepth'];
            $this->cfg['collect'] = json_decode(str_replace('summary:', '', $this->cfg['collect']), true);
        }
    }

    /**
     * Initiate the beginning of "summary" log entries
     *
     * When possible summary entries will be displayed at the beginning of the log
     * call groupEnd() (at matching group depth) to end summary
     *
     * groupSummary can be used multiple times
     * All groupSummary groups will appear together in a single group
     *
     * @param integer $priority (0) The higher the priority, the ealier it will appear.
     *
     * @return void
     */
    public function groupSummary($priority = 0)
    {
        if (!is_string($this->cfg['collect']) || strpos($this->cfg['collect'], 'summary') === false) {
            $this->cfg['collect'] = 'summary:'.json_encode($this->cfg['collect']);
            $this->data['groupDepthSummary']++;
        }
        if (!isset($this->data['logSummary'][$priority])) {
            $this->data['logSummary'][$priority] = array();
        }
        $this->logRef = &$this->data['logSummary'][$priority];
        $this->groupDepthRef = &$this->data['groupDepthSummary'];
        $this->appendLog('groupSummary', array($priority));
    }

    /**
     * Sets ancestor groups to uncollapsed
     *
     * @return void
     */
    public function groupUncollapse()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $curDepth = $this->groupDepthRef;   // will fluctuate as we go through log
        $minDepth = $this->groupDepthRef;   // decrease as we work our way down
        for ($i = count($this->logRef) - 1; $i >=0; $i--) {
            if ($curDepth < 1) {
                break;
            }
            $method = $this->logRef[$i][0];
            if (in_array($method, array('group', 'groupCollapsed'))) {
                $curDepth--;
                if ($curDepth < $minDepth) {
                    $minDepth--;
                    $this->logRef[$i][0] = 'group';
                }
            } elseif ($method == 'groupEnd') {
                $curDepth++;
            }
        }
        $this->appendLog('groupUncollapse', array());   // want to dispath event, but not actually log
    }

    /**
     * Informative logging information
     *
     * @return void
     */
    public function info()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $this->appendLog('info', $args);
        }
    }

    /**
     * For logging general information
     *
     * @return void
     */
    public function log()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $this->appendLog('log', $args);
        }
    }

    /**
     * Output array as a table
     * accepts
     *    array[, string]
     *    string, array
     *
     * @return void
     */
    public function table()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $argsLabel = array();
            $haveArray = false;
            foreach ($args as $k => $v) {
                if (!is_array($v) || $haveArray) {
                    if (!is_array($v)) {
                        $argsLabel[] = (string) $v;
                    }
                    unset($args[$k]);
                } else {
                    $haveArray = true;
                }
            }
            $method = 'table';
            if ($haveArray) {
                if (!empty($argsLabel)) {
                    $args[] = implode(' ', $argsLabel);
                }
            } else {
                $method = 'log';
                $args = $argsLabel;
                if (count($args) == 2 && !is_string($args[0])) {
                    $args[] = array_shift($args);
                }
            }
            $this->appendLog($method, $args);
        }
    }

    /**
     * Start a timer identified by label
     *
     * Label passed
     *    if doesn't exist: starts timer
     *    if does exist: unpauses (does not reset)
     * Label not passed
     *    timer will be added to a no-label stack
     *
     * Does not append log.   use timeEnd or timeGet to get time
     *
     * @param string $label unique label
     *
     * @return void
     */
    public function time($label = null)
    {
        if (isset($label)) {
            $timers = &$this->data['timers']['labels'];
            if (!isset($timers[$label])) {
                // new label
                $timers[$label] = array(0, microtime(true));
            } elseif (!isset($timers[$label][1])) {
                // no microtime -> the timer is currently paused -> unpause
                $timers[$label][1] = microtime(true);
            }
        } else {
            $this->data['timers']['stack'][] = microtime(true);
        }
        return;
    }

    /**
     * Behaves like a stopwatch.. returns running time
     *    If label is passed, timer is "paused"
     *    If label is not passed, timer is removed from timer stack
     *
     * @param string         $label            unique label
     * @param string|boolean $returnOrTemplate string: "%label: %time"
     *                                         boolean:  If true, only return time, rather than log it
     *
     * @return float
     */
    public function timeEnd($label = null, $returnOrTemplate = false)
    {
        if (is_bool($label) || strpos($label, '%time') !== false) {
            $returnOrTemplate = $label;
            $label = null;
        }
        $ret = $this->timeGet($label, true, null); // get non-rounded running time
        if (isset($label)) {
            if (isset($this->data['timers']['labels'][$label])) {
                $this->data['timers']['labels'][$label] = array(
                    $ret,  // store the new "running" time
                    null,  // "pause" the timer
                );
            }
        } else {
            $label = 'time';
            array_pop($this->data['timers']['stack']);
        }
        $ret = round($ret, 4);
        $this->timeLog($ret, $returnOrTemplate, $label);
        return $ret;
    }

    /**
     * Get the running time without stopping/pausing the timer
     *
     * @param string         $label            (optional) unique label
     * @param string|boolean $returnOrTemplate string: "%label: %time"
     *                                         boolean:  If true, only return time, rather than log it
     * @param integer        $precision        rounding precision (pass null for no rounding)
     *
     * @return float
     */
    public function timeGet($label = null, $returnOrTemplate = false, $precision = 4)
    {
        if (is_bool($label) || strpos($label, '%time') !== false) {
            $precision = $returnOrTemplate;
            $returnOrTemplate = $label;
            $label = null;
        }
        $microT = 0;
        $ellapsed = 0;
        if (!isset($label)) {
            $label = 'time';
            if (empty($this->data['timers']['stack'])) {
                list($ellapsed, $microT) = $this->data['timers']['labels']['debugInit'];
            } else {
                $microT = end($this->data['timers']['stack']);
            }
        } elseif (isset($this->data['timers']['labels'][$label])) {
            list($ellapsed, $microT) = $this->data['timers']['labels'][$label];
        }
        if ($microT) {
            $ellapsed += microtime(true) - $microT;
        }
        if (is_int($precision)) {
            $ellapsed = round($ellapsed, $precision);
        }
        $this->timeLog($ellapsed, $returnOrTemplate, $label);
        return $ellapsed;
    }

    /**
     * Log a warning
     *
     * @return void
     */
    public function warn()
    {
        if ($this->cfg['collect']) {
            $args = func_get_args();
            $this->appendLog('warn', $args);
        }
    }

    /*
        "Non-Methods"
    */

    /**
     * Extend debug with a plugin
     *
     * @param PluginInterface $plugin object implementing PluginInterface
     *
     * @return void
     */
    public function addPlugin(PluginInterface $plugin)
    {
        foreach ($plugin->debugListeners($this) as $eventName => $mixed) {
            if (is_string($mixed)) {
                $this->eventManager->addListener($eventName, array($plugin, $mixed));
            } else {
                if (count($mixed) == 2 && is_int($mixed[1])) {
                    // eventName => array('methodName', priority);
                    $this->eventManager->addListener($eventName, array($plugin, $mixed[0]), $mixed[1]);
                    continue;
                }
                // eventName => array(
                //     methodName || array(methodName) || array(methodName, priority)
                //     ...
                // )
                foreach ($mixed as $mixed2) {
                    if (is_string($mixed2)) {
                        $methodName = $mixed2;
                        $priority = 0;
                    } else {
                        $methodName = $mixed2[0];
                        $priority = isset($mixed2[1]) ? $mixed2[1] : 0;
                    }
                    $this->eventManager->addListener($eventName, array($plugin, $methodName), $priority);
                }
            }
        }
    }

    /**
     * Send an email
     *
     * @param string $emailTo to
     * @param string $subject subject
     * @param string $body    body
     *
     * @return void
     */
    public function email($emailTo, $subject, $body)
    {
        call_user_func($this->cfg['emailFunc'], $emailTo, $subject, $body);
    }

    /**
     * Retrieve a configuration value
     *
     * @param string $path what to get
     *
     * @return mixed
     */
    public function getCfg($path = null)
    {
        return $this->config->getCfg($path);
    }

    /**
     * Advanced usage
     *
     * @param string $path path
     *
     * @return mixed
     */
    public function getData($path = null)
    {
        if ($path == 'entryCount') {
            return count($this->data['log']);
        }
        $path = array_filter(preg_split('#[\./]#', $path), 'strlen');
        $ret = $this->data;
        foreach ($path as $k) {
            if (isset($ret[$k])) {
                $ret = $ret[$k];
            } else {
                $ret = null;
                break;
            }
        }
        return $ret;
    }

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @param array $cfg optional config
     *
     * @return object
     */
    public static function getInstance($cfg = array())
    {
        if (!isset(self::$instance)) {
            $className = __CLASS__;
            // self::$instance set in __construct
            new $className($cfg);
        } elseif ($cfg) {
            self::$instance->setCfg($cfg);
        }
        return self::$instance;
    }

    /**
     * Dispatches debug.output event and returns result
     *
     * @return string or void
     */
    public function output()
    {
        $return = null;
        if ($this->cfg['output']) {
            while ($this->data['groupDepth'] > 0) {
                $this->data['groupDepth']--;
                $this->data['log'][] = array('groupEnd');
            }
            $outputAs = $this->output->getCfg('outputAs');
            $this->output->setCfg('outputAs', $outputAs);
            $return = $this->eventManager->dispatch('debug.output', $this, array('output'=>''))['output'];
            $this->data['outputSent'] = true;
            $this->data['log'] = array();
            $this->data['logSummary'] = array();
            $this->data['alerts'] = array();
        } else {
            $this->eventManager->dispatch('debug.output', $this, array('output'=>''));
        }
        return $return;
    }

    /**
     * Set one or more config values
     *
     * If setting a value via method a or b, old value is returned
     *
     * Setting/updating 'key' will also set 'collect' and 'output'
     *
     *    setCfg('key', 'value')
     *    setCfg('level1.level2', 'value')
     *    setCfg(array('k1'=>'v1', 'k2'=>'v2'))
     *
     * @param string|array $path   path
     * @param mixed        $newVal value
     *
     * @return mixed
     */
    public function setCfg($path, $newVal = null)
    {
        return $this->config->setCfg($path, $newVal);
    }

    /**
     * Advanced usage
     *
     * @param string|array $path  path
     * @param mixed        $value value
     *
     * @return void
     */
    public function setData($path, $value)
    {
        if (is_string($path)) {
            $path = preg_split('#[\./]#', $path);
            $ref = &$this->data;
            foreach ($path as $k) {
                $ref = &$ref[$k];
            }
            $ref = $value;
        } else {
            $this->data = $this->utilities->arrayMergeDeep($this->data, $path);
        }
    }

    /**
     * A wrapper for errorHandler->setErrorCaller
     *
     * @param array $caller optional. pass null or array() to clear
     *
     * @return void
     */
    public function setErrorCaller($caller = 'notPassed')
    {
        if (!empty($caller) && $caller != 'notPassed') {
            $caller['depth'] = $this->data['groupDepth'];
        }
        $this->errorHandler->setErrorCaller($caller, 2);
    }

    /*
        "Non-Public" methods
    */

    /**
     * Debug class autoloader
     *
     * @param string $className classname to attempt to load
     *
     * @return void
     */
    protected function autoloader($className)
    {
        $className = ltrim($className, '\\'); // leading backslash _shouldn't_ have been passed
        if (preg_match('/^(.*?)\\\\([^\\\\]+)$/', $className, $matches) && $matches[1] === __NAMESPACE__.'\\Debug') {
            $filePath = __DIR__.'/'.$matches[2].'.php';
            require $filePath;
        }
    }

    /**
     * Store the arguments
     * will be output when output method is called
     *
     * @param string $method error, info, log, warn
     * @param array  $args   arguments passed to method
     *
     * @return void
     */
    protected function appendLog($method, $args)
    {
        foreach ($args as $i => $v) {
            if ($this->abstracter->needsAbstraction($v)) {
                if ($method == 'table' && is_array($v)) {
                    // handle separately... could be an array of \Traversable objects
                    $args[$i] = $this->abstracter->getAbstractionTable($v);
                } else {
                    $args[$i] = $this->abstracter->getAbstraction($v);
                }
            }
        }
        array_unshift($args, $method);
        /*
            if logging an error or warn, also log originating file/line
        */
        if (in_array($method, array('error','warn'))) {
            $args[] = $this->internal->getErrorCaller();
        }
        if ($this->eventManager->dispatch('debug.log', $this, $args)->isPropagationStopped()) {
            return;
        }
        if ($method == 'groupUncollapse') {
            // don't log this method (we wanted to dispatch event)
            return;
        }
        if ($method == 'alert') {
            $this->data['alerts'][] = array_slice($args, 1);
        } else {
            $this->logRef[] = $args;
        }
        if ($this->cfg['file']) {
            $this->appendLogFile($args);
        }
        return;
    }

    /**
     * Appends log entry to $this->cfg['file']
     *
     * @param array $args args
     *
     * @return boolean
     */
    protected function appendLogFile($args)
    {
        $success = false;
        $method = array_shift($args);
        $fileHandle = $this->getFileHandle();
        if ($fileHandle) {
            $success = true;
            if ($args) {
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
                $this->setCfg('file', null);
            }
        }
        return $this->data['fileHandle'];
    }

    /**
     * Set/cache this class' public methods
     *
     * @return void
     */
    protected function setPublicMethods()
    {
        $refObj = new \ReflectionObject($this);
        self::$publicMethods = array_map(function (\ReflectionMethod $refMethod) {
            return $refMethod->name;
        }, $refObj->getMethods(\ReflectionMethod::IS_PUBLIC));
    }

    /**
     * Log time
     *
     * @param float  $seconds          seconds
     * @param mixed  $returnOrTemplate false: log the time (default)
     *                                 true: do not log
     *                                 string: log using passed template
     * @param string $label            label
     *
     * @return void
     */
    protected function timeLog($seconds, $returnOrTemplate = false, $label = 'time')
    {
        if (!is_string($returnOrTemplate)) {
            if (!$returnOrTemplate) {
                $this->appendLog('time', array($label.': '.$seconds.' sec'));
            }
        } else {
            $str = $returnOrTemplate;
            $str = str_replace('%label', $label, $str);
            $str = str_replace('%time', $seconds, $str);
            $this->appendLog('time', array($str));
        }
    }
}
