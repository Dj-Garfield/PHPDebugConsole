<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 *
 * @link http://www.github.com/bkdotcom/PHPDebugConsole
 * @link https://developer.mozilla.org/en-US/docs/Web/API/console
 */

namespace bdk;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\AssetProviderInterface;
use bdk\Debug\ConfigurableInterface;
use bdk\Debug\LogEntry;
use bdk\Debug\Psr7lite\HttpFoundationBridge;
use bdk\Debug\Route\RouteInterface;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use bdk\PubSub\SubscriberInterface;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface; // PSR-7
use ReflectionMethod;
use SplObjectStorage;
use Symfony\Component\HttpFoundation\Response as HttpFoundationResponse;

/**
 * Web-browser/javascript like console class for PHP
 *
 * @method Abstraction|string prettify(string $string, string $contentType)
 * @method void email($toAddr, $subject, $body)
 * @method string getInterface()
 * @method string getResponseCode()
 * @method string getResponseHeader($header = 'Content-Type')
 * @method array|string getResponseHeaders($asString = false)
 * @method mixed getServerParam($name, $default = null)
 * @method bool hasLog()
 * @method mixed redact($val, $key = null)
 * @method string requestId()
 *
 * @property Abstracter           $abstracter    lazy-loaded Abstracter instance
 * @property \bdk\Backtrace       $backtrace     lazy-loaded Backtrace instance
 * @property \bdk\ErrorHandler\ErrorEmailer $errorEmailer lazy-loaded ErrorEmailer instance
 * @property \bdk\ErrorHandler    $errorHandler  lazy-loaded ErrorHandler instance
 * @property \bdk\PubSub\Manager  $eventManager  lazy-loaded Event Manager instance
 * @property Debug\Utility\Html   $html          lazy=loaded Html Utility instance
 * @property Debug\Psr3\Logger    $logger        lazy-loaded PSR-3 instance
 * @property Debug\Method\Clear   $methodClear   lazy-loaded MethodClear instance
 * @property Debug\Method\Profile $methodProfile lazy-loaded MethodProfile instance
 * @property Debug\Method\Table   $methodTable   lazy-loaded MethodTable instance
 * @property \bdk\Debug|null      $parentInstance parent "channel"
 * @property \Psr\Http\Message\ResponseInterface $response lazy-loaded ResponseInterface (set via writeToResponse)
 * @property Debug\Psr7lite\ServerRequest $request lazy-loaded ServerRequest
 * @property \bdk\Debug           $rootInstance  root "channel"
 * @property Debug\Utility\StopWatch $stopWatch  lazy-loaded StopWatch Instance
 * @property Debug\Utility\Utf8   $utf8          lazy-loaded Utf8 instance
 * @property Debug\Utility        $utility       lazy-loaded Utility instance
 *
 * @psalm-consistent-constructor
 */
class Debug
{

    const CLEAR_ALERTS = 1;
    const CLEAR_LOG = 2;
    const CLEAR_LOG_ERRORS = 4;
    const CLEAR_SUMMARY = 8;
    const CLEAR_SUMMARY_ERRORS = 16;
    const CLEAR_ALL = 31;
    const CLEAR_SILENT = 32;
    const CONFIG_DEBUG = 'configDebug';
    const CONFIG_INIT = 'configInit';
    const COUNT_NO_INC = 1;
    const COUNT_NO_OUT = 2;

    const EVENT_BOOTSTRAP = 'debug.bootstrap';
    const EVENT_CONFIG = 'debug.config';
    const EVENT_DUMP_CUSTOM = 'debug.dumpCustom';
    const EVENT_LOG = 'debug.log';
    const EVENT_MIDDLEWARE = 'debug.middleware';
    const EVENT_OBJ_ABSTRACT_END = 'debug.objAbstractEnd';
    const EVENT_OBJ_ABSTRACT_START = 'debug.objAbstractStart';
    const EVENT_OUTPUT = 'debug.output';
    const EVENT_OUTPUT_LOG_ENTRY = 'debug.outputLogEntry';
    const EVENT_PLUGIN_INIT = 'debug.pluginInit';
    const EVENT_PRETTIFY = 'debug.prettify';
    const EVENT_STREAM_WRAP = 'debug.streamWrap';

    const META = "\x00meta\x00";
    const VERSION = '3.0';

    protected $cfg = array();
    protected $config;
    protected $data = array(
        'alerts'            => array(), // alert entries.  alerts will be shown at top of output when possible
        'counts'            => array(), // count method
        'entryCountInitial' => 0,       // store number of log entries created during init
        'groupStacks' => array(
            'main' => array(),  // array('channel' => Debug, 'collect' => bool)[]
        ),
        'groupPriorityStack' => array(), // array of priorities
                                        //   used to return to the previous summary when groupEnd()ing out of a summary
                                        //   this allows calling groupSummary() while in a groupSummary
        'headers'           => array(), // headers that need to be output (ie chromeLogger & firePhp)
        'log'               => array(),
        'logSummary'        => array(), // summary log entries subgrouped by priority
        'outputSent'        => false,
        'profileAutoInc'    => 1,
        'profileInstances'  => array(),
        'requestId'         => '',  // set in bootstrap
        'runtime'           => array(
            // memoryPeakUsage, memoryLimit, & memoryLimit get stored here
        ),
    );
    protected $groupStackRef;   // points to $this->data['groupStacks'][x] (where x = 'main' or (int) priority)
    protected $internal;
    protected $internalEvents;
    protected $logRef;          // points to either log or logSummary[priority]
    protected static $methodDefaultArgs = array();
    protected $readOnly = array(
        'parentInstance' => null,
        'rootInstance' => null,
    );
    protected $registeredPlugins;   // SplObjectHash

    private static $instance;
    private $channels = array();

    /**
     * Constructor
     *
     * @param array $cfg config
     */
    public function __construct($cfg = array())
    {
        $this->cfg = array(
            'collect'   => false,
            'key'       => null,
            'output'    => false,           // output the log?
            'channels' => array(
                /*
                channelName => array(
                    'channelIcon'
                    'channelShow'
                    'nested'
                )
                */
            ),
            'channelIcon' => 'fa fa-list-ul',
            'channelName' => 'general',     // channel or tab name
            'channelShow' => true,          // wheter initially filtered or not
            'channelSort' => 0, // if non-nested channel (tab), sort order
                            // higher = first
                            // tabs with same sort will be sorted alphabetically
            'enableProfiling' => false,
            // which error types appear as "error" in debug console... all other errors are "warn"
            'errorMask' => E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR
                            | E_WARNING | E_USER_ERROR | E_RECOVERABLE_ERROR,
            'emailFrom' => null,    // null = use php's default (php.ini: sendmail_from)
            'emailFunc' => 'mail',  // callable
            'emailLog' => false,    // Whether to email a debug log.  (requires 'collect' to also be true)
                                    //   false:             email will not be sent
                                    //   true or 'always':  email sent (if log is not output)
                                    //   'onError':         email sent if error occured (unless output)
            'emailTo' => 'default', // will default to $_SERVER['SERVER_ADMIN'] if non-empty, null otherwise
            'exitCheck' => true,
            'factories' => $this->getDefaultFactories(),
            'headerMaxAll' => 250000,
            'headerMaxPer' => null,
            'logEnvInfo' => array(      // may be set by passing a list
                'errorReporting' => true,
                'files' => true,
                'gitInfo' => true,
                'phpInfo' => true,
                'serverVals' => true,
                'session' => true,
            ),
            'logRequestInfo' => array(
                'cookies' => true,
                'files' => true,
                'headers' => true,
                'post' => true,
            ),
            'logResponse' => 'auto',
            'logResponseMaxLen' => '1 MB',
            'logRuntime' => true,
            'logServerKeys' => array('REMOTE_ADDR','REQUEST_TIME','REQUEST_URI','SERVER_ADDR','SERVER_NAME'),
            'onBootstrap' => null,          // callable
            'onLog' => null,                // callable
            'onOutput' => null,             // callable
            'outputHeaders' => true,        // ie, ChromeLogger and/or firePHP headers
            'redactKeys' => array(          // case-insensitive
                'password',
            ),
            'redactReplace' => function ($str, $key) {
                // "use" our function params so things (ie phpmd) don't complain
                array($str, $key);
                return '█████████';
            },
            'route' => 'auto',              // 'auto', chromeLogger', 'firephp', 'html', 'script', 'steam', 'text', or RouteInterface,
                                            //   if 'auto', will be determined automatically
                                            //   if null, no output (unless output plugin added manually)
            'routeNonHtml' => 'chromeLogger',
            'services' => $this->getDefaultServices(),
            'sessionName' => null,  // if logging session data (see logEnvInfo), optionally specify session name
        );
        $this->bootstrap($cfg);
    }

    /**
     * Magic method... inaccessible method called.
     *
     * If method not found in internal class, treat as a custom method.
     *
     * @param string $methodName Inaccessible method name
     * @param array  $args       Arguments passed to method
     *
     * @return mixed
     */
    public function __call($methodName, $args)
    {
        $callable = array($this->internal, $methodName);
        if (\is_callable($callable)) {
            return \call_user_func_array($callable, $args);
        }
        return $this->appendLog(new LogEntry(
            $this,
            $methodName,
            $args,
            array('isCustomMethod' => true)
        ));
    }

    /**
     * Magic method to allow us to call instance methods statically
     *
     * Prefix the instance method with an underscore ie
     *    \bdk\Debug::_log('logged via static method');
     *
     * @param string $methodName Inaccessible method name
     * @param array  $args       Arguments passed to method
     *
     * @return mixed
     */
    public static function __callStatic($methodName, $args)
    {
        $methodName = \ltrim($methodName, '_');
        if (!self::$instance) {
            if ($methodName === 'setCfg') {
                /*
                    Treat as a special case
                    Want to initialize with the passed config vs initialize, then setCfg
                    ie _setCfg(array('route'=>'html')) via command line
                    we don't want to first initialize with default STDERR output
                */
                $cfg = \is_array($args[0])
                    ? $args[0]
                    : array($args[0] => $args[1]);
                new static($cfg);
                return;
            }
            new static();
        }
        /*
            Add 'statically' meta arg
            Not all methods expect meta args... so make sure it comes after expected args
        */
        $defaultArgs = self::getMethodDefaultArgs($methodName);
        $args = \array_replace($defaultArgs, $args);
        $args[] = self::meta('statically');
        return \call_user_func_array(array(self::$instance, $methodName), $args);
    }

    /**
     * Magic method to get inaccessible / undefined properties
     * Lazy load child classes
     *
     * @param string $property property name
     *
     * @return mixed property value
     */
    public function __get($property)
    {
        if (\in_array($property, array('config', 'internal', 'internalEvents'))) {
            $caller = $this->backtrace->getCallerInfo();
            $this->errorHandler->handleError(
                E_USER_NOTICE,
                'property "' . $property . '" is not accessible',
                $caller['file'],
                $caller['line']
            );
            return;
        }
        $val = $this->getViaContainer($property);
        if ($val !== false) {
            // may be null
            return $val;
        }
        /*
            "Read-only" properties
        */
        if (\array_key_exists($property, $this->readOnly)) {
            return $this->readOnly[$property];
        }
        /*
            Check getter method (although not utilized)
        */
        $getter = 'get' . \ucfirst($property);
        if (\method_exists($this, $getter)) {
            return $this->{$getter}();
        }
        return null;
    }

    /*
        Debugging Methods
    */

    /**
     * Display an alert at the top of the log
     *
     * Can use styling & substitutions.
     * If using substitutijons, will need to pass $level & $dismissible as meta values
     *
     * @param string $message     message
     * @param string $level       (error), info, success, warn
     *                               "danger" and "warning" are still accepted, however deprecated
     * @param bool   $dismissible (false) Whether to display a close icon/button
     *
     * @return void
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function alert($message, $level = 'error', $dismissible = false)
    {
        $args = \func_get_args();
        /*
            Create a temporary LogEntry so we can test if we passed substitutions
        */
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            $args
        );
        $levelsAllowed = array('danger','error','info','success','warn','warning');
        $haveSubstitutions = $logEntry->containsSubstitutions() && \array_key_exists(1, $args) && !\in_array($args[1], $levelsAllowed);
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            $args,
            array(
                'level' => 'error',
                'dismissible' => false,
            ),
            $haveSubstitutions
                ? array()
                : array(
                    'message' => null,
                    'level' => 'error',
                    'dismissible' => false,
                ),
            array('level','dismissible')
        );
        $level = $logEntry->getMeta('level');
        /*
            Continue to allow bootstrap "levels"
        */
        $levelTrans = array(
            'danger' => 'error',
            'warning' => 'warn',
        );
        if (isset($levelTrans[$level])) {
            $level = $levelTrans[$level];
        } elseif (!\in_array($level, $levelsAllowed)) {
            $level = 'error';
        }
        $logEntry->setMeta('level', $level);
        $this->setLogDest('alerts');
        $this->appendLog($logEntry);
        $this->setLogDest('auto');
    }

    /**
     * If first argument evaluates `false`, log the remaining paramaters
     *
     * Supports styling & substitutions
     *
     * @param bool  $assertion Any boolean expression. If the assertion is false, the message is logged
     * @param mixed $msg,...   (optional) variable num of values to output if assertion fails
     *                           if none provided, will use calling file & line num
     *
     * @return void
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function assert($assertion, $msg = null)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        );
        $args = $logEntry['args'];
        $assertion = \array_shift($args);
        if ($assertion) {
            return;
        }
        if (!$args) {
            // add default message
            $callerInfo = $this->backtrace->getCallerInfo();
            $args = array(
                'Assertion failed:',
                \sprintf('%s (line %s)', $callerInfo['file'], $callerInfo['line']),
            );
            $logEntry->setMeta('detectFiles', true);
        }
        $logEntry['args'] = $args;
        $this->appendLog($logEntry);
    }

    /**
     * Clear the log
     *
     * This method executes even if `collect` is false
     *
     * @param int $flags A bitmask of options
     *                     `self::CLEAR_ALERTS` : Clear alerts generated with `alert()`
     *                     `self::CLEAR_LOG` : **default** Clear log entries (excluding warn & error)
     *                     `self::CLEAR_LOG_ERRORS` : Clear log, warn, & error
     *                     `self::CLEAR_SUMMARY` : Clear summary entries (excluding warn & error)
     *                     `self::CLEAR_SUMMARY_ERRORS` : Clear summary warn & error
     *                     `self::CLEAR_ALL` :  clear all everything
     *                     `self::CLEAR_SILENT` : Don't add log entry
     *
     * @return void
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function clear($flags = self::CLEAR_LOG)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(),
            array(
                'bitmask' => self::CLEAR_LOG,
            ),
            array('bitmask')
        );
        $this->methodClear->onLog($logEntry);
        // even if cleared from within summary, let's log this in primary log
        $this->setLogDest('log');
        $this->appendLog($logEntry, $logEntry['publish']);
        $this->setLogDest('auto');
    }

    /**
     * Log the number of times this has been called with the given label.
     *
     * Count is maintained even when `collect` is false
     * If collect = false, `count()` will be performed "silently"
     *
     * @param mixed $label Label.  If omitted, logs the number of times `count()` has been called at this particular line.
     * @param int   $flags (optional) A bitmask of
     *                        \bdk\Debug::COUNT_NO_INC` : don't increment the counter
     *                                                     (ie, just get the current count)
     *                        \bdk\Debug::COUNT_NO_OUT` : don't output/log
     *
     * @return int The new count (or current count when using `COUNT_NO_INC`)
     */
    public function count($label = null, $flags = 0)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        );
        // label may be ommitted and only flags passed as a single argument
        //   (excluding potential meta argument)
        $args = $logEntry['args'];
        if (\count($args) === 1 && \is_int($args[0])) {
            $label = null;
            $flags = $args[0];
        }
        $dataLabel = (string) $label;
        if ($label === null) {
            // determine dataLabel from calling file & line
            $callerInfo = $this->backtrace->getCallerInfo();
            $logEntry['meta'] = \array_merge(array(
                'file' => $callerInfo['file'],
                'line' => $callerInfo['line'],
            ), $logEntry['meta']);
            $label = 'count';
            $dataLabel = $logEntry['meta']['file'] . ': ' . $logEntry['meta']['line'];
        }
        if (!isset($this->data['counts'][$dataLabel])) {
            $this->data['counts'][$dataLabel] = 0;
        }
        if (!($flags & self::COUNT_NO_INC)) {
            $this->data['counts'][$dataLabel]++;
        }
        $count = $this->data['counts'][$dataLabel];
        if (!($flags & self::COUNT_NO_OUT)) {
            $logEntry['args'] = array(
                (string) $label,
                $count,
            );
            $this->appendLog($logEntry);
        }
        return $count;
    }

    /**
     * Resets the counter
     *
     * Counter is reset even when debugging is disabled (ie collect = false).
     *
     * @param mixed $label (optional) specify the counter to reset
     * @param int   $flags (optional) currently only one option :
     *                       \bdk\Debug::COUNT_NO_OUT` : don't output/log
     *
     * @return void
     */
    public function countReset($label = 'default', $flags = null)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        );
        // label may be ommitted and only flags passed as a single argument
        //   (excluding potential meta argument)
        $args = $logEntry['args'];
        if (\count($args) === 1 && \is_int($args[0])) {
            $label = 'default';
            $flags = $args[0];
        }
        $logEntry['args'] = array('Counter \'' . $label . '\' doesn\'t exist.');
        if (isset($this->data['counts'][$label])) {
            $this->data['counts'][$label] = 0;
            $logEntry['args'] = array(
                (string) $label,
                0,
            );
        }
        if (!($flags & self::COUNT_NO_OUT)) {
            $this->appendLog($logEntry);
        }
    }

    /**
     * Log an error message.
     *
     * Supports styling & substitutions
     *
     * @param mixed $arg,... message / values
     *
     * @return void
     */
    public function error()
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(
                'detectFiles' => true,
                'uncollapse' => true,
            )
        );
        // file & line meta may already be set (ie coming via errorHandler)
        // file & line may also be defined as null
        $default = "\x00default\x00";
        if ($logEntry->getMeta('file', $default) === $default) {
            $callerInfo = $this->backtrace->getCallerInfo();
            $logEntry->setMeta(array(
                'file' => $callerInfo['file'],
                'line' => $callerInfo['line'],
            ));
        }
        $this->appendLog($logEntry);
    }

    /**
     * Create a new inline group
     *
     * Groups generally get indented and will receive an expand/collapse toggle.
     *
     * applicable meta args:
     *      argsAsParams: true
     *      boldLabel: true
     *      hideIfEmpty: false
     *      isFuncName: (bool)
     *      level: (string)
     *      ungroup: false  // when closed: if no children, convert to plain log entry
     *                      // when closed: if only one child, remove the containing group
     *
     * @param mixed $arg,... label / values
     *
     * @return void
     */
    public function group()
    {
        $this->doGroup(__FUNCTION__, \func_get_args());
    }

    /**
     * Create a new inline group
     *
     * Unline group(), groupCollapsed(), will initially be collapsed
     *
     * @param mixed $arg,... label / values
     *
     * @return void
     */
    public function groupCollapsed()
    {
        $this->doGroup(__FUNCTION__, \func_get_args());
    }

    /**
     * Close current group
     *
     * Every call to `group()` and `groupCollapsed()` should be paired with `groupEnd()`
     *
     * The optional return value will be visible when the group is both expanded and collapsed.
     *
     * @param mixed $value (optional) "return" value
     *
     * @return void
     */
    public function groupEnd($value = Abstracter::UNDEFINED)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__
        );
        $haveOpenGroup = $this->haveOpenGroup();
        if ($haveOpenGroup === 2) {
            // we're closing a summary group
            $priorityClosing = \array_pop($this->data['groupPriorityStack']);
            // not really necessary to remove this empty placeholder, but lets keep things tidy
            unset($this->data['groupStacks'][$priorityClosing]);
            $this->setLogDest('auto');
            /*
                Publish the Debug::EVENT_LOG event (regardless of cfg.collect)
                don't actually log
            */
            $logEntry['appendLog'] = false;
            $logEntry->setMeta('closesSummary', true);
            $this->appendLog($logEntry, true);
        } elseif ($haveOpenGroup === 1) {
            \array_pop($this->readOnly['rootInstance']->groupStackRef);
            if ($value !== Abstracter::UNDEFINED) {
                $this->appendLog(new LogEntry(
                    $this,
                    'groupEndValue',
                    array('return', $value)
                ));
            }
            $this->appendLog($logEntry);
        }
        $errorCaller = $this->errorHandler->get('errorCaller');
        if ($errorCaller && isset($errorCaller['groupDepth']) && $this->getGroupDepth() < $errorCaller['groupDepth']) {
            $this->errorHandler->setErrorCaller(false);
        }
    }

    /**
     * Open a "summary" group
     *
     * Debug methods called from within a groupSummary will appear at the top of the log.
     * Call `groupEnd()` to close the summary group
     *
     * All groupSummary groups will appear together in a single group
     *
     * @param int $priority (0) The higher the priority, the earlier it will appear.
     *
     * @return void
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function groupSummary($priority = 0)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(),
            array(
                'priority' => 0,
            ),
            array('priority')
        );
        $this->data['groupPriorityStack'][] = $logEntry['meta']['priority'];
        $this->setLogDest('summary');
        /*
            Publish the Debug::EVENT_LOG event (regardless of cfg.collect)
            don't actually log
        */
        $logEntry['appendLog'] = false;
        // groupSumary's Debug::EVENT_LOG event should happen on the root instance
        $this->readOnly['rootInstance']->appendLog($logEntry, true);
    }

    /**
     * Uncollapse ancestor groups
     *
     * This will only occur if `cfg['collect']` is currently `true`
     *
     * @return void
     */
    public function groupUncollapse()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        );
        $groups = $this->internal->getCurrentGroups('auto');
        foreach ($groups as $groupLogEntry) {
            $groupLogEntry['method'] = 'group';
        }
        /*
            Publish the Debug::EVENT_LOG event (regardless of cfg.collect)
            don't actually log
        */
        $logEntry['appendLog'] = false;
        $this->appendLog($logEntry, true);
    }

    /**
     * Log some informative information
     *
     * Supports styling & substitutions
     *
     * @param mixed $arg,... message / values
     *
     * @return void
     */
    public function info()
    {
        $this->appendLog(new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        ));
    }

    /**
     * Log general information
     *
     * Supports styling & substitutions
     *
     * @param mixed $arg,... message / values
     *
     * @return void
     */
    public function log()
    {
        $args = \func_get_args();
        if (\count($args) === 1) {
            if ($args[0] instanceof LogEntry) {
                $this->appendLog($args[0]);
                return;
            }
            if ($args[0] instanceof Error) {
                $this->internalEvents->onError($args[0]);
                return;
            }
        }
        $this->appendLog(new LogEntry(
            $this,
            __FUNCTION__,
            $args
        ));
    }

    /**
     * Starts recording a performance profile
     *
     * @param string $name Optional Profile name
     *
     * @return void
     */
    public function profile($name = null)
    {
        if (!$this->cfg['collect']) {
            return;
        }
        if (!$this->cfg['enableProfiling']) {
            $callerInfo = $this->backtrace->getCallerInfo();
            $msg = \sprintf(
                'Profile: Unable to start - enableProfiling opt not set.  %s on line %s.',
                $callerInfo['file'],
                $callerInfo['line']
            );
            $this->appendLog(new LogEntry(
                $this,
                __FUNCTION__,
                array($msg)
            ));
            return;
        }
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(),
            array(
                'name' => null,
            ),
            array('name')
        );
        if ($logEntry['meta']['name'] === null) {
            $logEntry['meta']['name'] = 'Profile ' . $this->data['profileAutoInc'];
            $this->data['profileAutoInc']++;
        }
        $name = $logEntry['meta']['name'];
        if (isset($this->data['profileInstances'][$name])) {
            $instance = $this->data['profileInstances'][$name];
            $instance->end();
            $instance->start();
            // move it to end (last started)
            unset($this->data['profileInstances'][$name]);
            $this->data['profileInstances'][$name] = $instance;
            $logEntry['args'] = array('Profile \'' . $name . '\' restarted');
            $this->appendLog($logEntry);
            return;
        }
        $this->data['profileInstances'][$name] = $this->methodProfile; // factory
        $logEntry['args'] = array('Profile \'' . $name . '\' started');
        $this->appendLog($logEntry);
    }

    /**
     * Stops recording profile info & adds info to the log
     *
     *  * if name is passed and it matches the name of a profile being recorded, then that profile is stopped.
     *  * if name is passed and it does not match the name of a profile being recorded, nothing will be done
     *  * if name is not passed, the most recently started profile is stopped (named, or non-named).
     *
     * @param string $name Optional Profile name
     *
     * @return void
     */
    public function profileEnd($name = null)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(),
            array(
                'name' => null
            ),
            array('name')
        );
        if ($logEntry['meta']['name'] === null) {
            \end($this->data['profileInstances']);
            $logEntry['meta']['name'] = \key($this->data['profileInstances']);
        }
        $name = $logEntry['meta']['name'];
        $args = array( $name !== null
            ? 'profileEnd: No such Profile: ' . $name
            : 'profileEnd: Not currently profiling'
        );
        if (isset($this->data['profileInstances'][$name])) {
            $instance = $this->data['profileInstances'][$name];
            $data = $instance->end();
            /*
                So that our row keys can receive 'callable' formatting,
                set special '__key' value
            */
            $tableInfo = $logEntry->getMeta('tableInfo', array());
            $tableInfo = \array_replace_recursive(array(
                'rows' => \array_fill_keys(\array_keys($data), array()),
            ), $tableInfo);
            foreach (\array_keys($data) as $k) {
                $tableInfo['rows'][$k]['key'] = new Abstraction(
                    Abstracter::TYPE_CALLABLE,
                    array(
                        'value' => $k,
                        'hideType' => true, // don't output 'callable'
                    )
                );
            }
            $caption = 'Profile \'' . $name . '\' Results';
            $args = array($caption, 'no data');
            if ($data) {
                $args = array( $data );
                $logEntry->setMeta(array(
                    'caption' => $caption,
                    'totalCols' => array('ownTime'),
                    'tableInfo' => $tableInfo,
                ));
            }
            unset($this->data['profileInstances'][$name]);
        }
        $logEntry['args'] = $args;
        $this->methodTable->onLog($logEntry);
        $this->appendLog($logEntry);
    }

    /**
     * Output array or object as a table
     *
     * Accepts array of arrays or array of objects
     *
     * Parameters:
     *   1st encountered array (or traversable) is the data
     *   2nd encountered array (optional) specifies columns to output
     *   1st encountered string is a label/caption
     *
     * @param mixed $arg,... traversable, [option array], [caption] in no particular order
     *
     * @return void
     */
    public function table()
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args()
        );
        $this->methodTable->onLog($logEntry);
        $this->appendLog($logEntry);
    }

    /**
     * Start a timer identified by label
     *
     * ## Label passed
     *  * if doesn't exist: starts timer
     *  * if does exist: unpauses (does not reset)
     *
     * ## Label not passed
     *  * timer will be added to a no-label stack
     *
     * Does not append log (unless duration is passed).
     *
     * Use `timeEnd` or `timeGet` to get time
     *
     * @param string $label    unique label
     * @param float  $duration (optional) duration (in seconds).  Use this param to log a duration obtained externally.
     *
     * @return void
     */
    public function time($label = null, $duration = null)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(
                // these meta values are used if duration is passed
                'precision' => 4,
                'silent' => false,
                'template' => '%label: %time',
                'unit' => 'auto',
            ),
            array(
                'label' => null,
                'duration' => null,
            )
        );
        $args = $logEntry['args'];
        $floats = \array_filter($args, function ($val) {
            return \is_float($val);
        });
        $args = \array_values(\array_diff_key($args, $floats));
        $label = $args[0];
        if ($floats) {
            $duration = \reset($floats);
            $logEntry['args'] = array($label);
            $this->internal->doTime($duration, $logEntry);
            return;
        }
        $this->stopWatch->start($label);
    }

    /**
     * Behaves like a stopwatch.. logs and returns running time
     *
     *    If label is passed, timer is "paused" (not ended/cleared)
     *    If label is not passed, timer is removed from timer stack
     *
     * Meta options
     *    precision: 4 (how many decimal places)
     *    silent: (false) only return / don't log
     *    template: '%label: %time'
     *    unit: ('auto'), 'sec', 'ms', or 'us'
     *
     * @param string $label (optional) unique label
     * @param bool   $log   (true) log it, or return only
     *                        if passed, takes precedence over silent meta val
     *
     * @return float|false The duration (in sec).
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function timeEnd($label = null, $log = true)
    {
        $logEntry = $this->timeLogEntry(__FUNCTION__, \func_get_args());
        $label = $logEntry['args'][0];
        $elapsed = $this->stopWatch->stop($label);
        $this->internal->doTime($elapsed, $logEntry);
        return $elapsed;
    }

    /**
     * Log/get the running time without stopping/pausing the timer
     *
     * Meta options
     *    precision: 4 (how many decimal places)
     *    silent: (false) only return / don't log
     *    template: '%label: %time'
     *    unit: ('auto'), 'sec', 'ms', or 'us'
     *
     * This method does not have a web console API equivalent
     *
     * @param string $label (optional) unique label
     * @param bool   $log   (true) log it, or return only
     *                        if passed, takes precedence over silent meta val
     *
     * @return float|false The duration (in sec).  `false` if specified label does not exist
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function timeGet($label = null, $log = true)
    {
        $logEntry = $this->timeLogEntry(__FUNCTION__, \func_get_args());
        $label = $logEntry['args'][0];
        $elapsed = $this->stopWatch->get($label);
        if ($elapsed === false) {
            if ($logEntry->getMeta('silent') === false) {
                $this->appendLog(new LogEntry(
                    $this,
                    __FUNCTION__,
                    array('Timer \'' . $label . '\' does not exist'),
                    $logEntry['meta']
                ));
            }
            return false;
        }
        $this->internal->doTime($elapsed, $logEntry);
        return $elapsed;
    }

    /**
     * Logs the current value of a timer that was previously started via `time()`
     *
     * also logs additional arguments
     *
     * @param string $label   (optional) unique label
     * @param mixed  $arg,... (optional) additional values to be logged with time
     *
     * @return void
     */
    public function timeLog($label = null, $args = null)
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(
                'precision' => 4,
                'unit' => 'auto',
            ),
            array(
                'label' => null,
            )
        );
        $args = $logEntry['args'];
        $label = $args[0];
        $elapsed = $this->stopWatch->get($label);
        $meta = $logEntry['meta'];
        if ($elapsed === false) {
            $this->appendLog(new LogEntry(
                $this,
                __FUNCTION__,
                array('Timer \'' . $label . '\' does not exist'),
                \array_diff_key($meta, \array_flip(array('precision','unit')))
            ));
            return;
        }
        $elapsed = $this->utility->formatDuration(
            $elapsed,
            $meta['unit'],
            $meta['precision']
        );
        $args[0] = $label . ': ';
        \array_splice($args, 1, 0, $elapsed);
        $logEntry['args'] = $args;
        $logEntry['meta'] = \array_diff_key($meta, \array_flip(array('precision','unit')));
        $this->appendLog($logEntry);
    }

    /**
     * Log a stack trace
     *
     * Essentially PHP's `debug_backtrace()`, but displayed as a table
     *
     * @param bool   $inclContext Include code snippet
     * @param string $caption     (optional) Specify caption for the trace table
     *
     * @return void
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     */
    public function trace($inclContext = false, $caption = 'trace')
    {
        if (!$this->cfg['collect']) {
            return;
        }
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(
                'columns' => array('file','line','function'),
                'detectFiles' => true,
                'inclArgs' => true, // incl arguments with context?
                                    //   may want to set meta['cfg']['objectsExclude'] = '*'
                'sortable' => false,
                'trace' => null,  // set to specify trace
            ),
            array(
                'inclContext' => false,
                'caption' => 'trace',
            ),
            array(
                'caption',
                'inclContext',
            )
        );
        // Get trace and include args if we're including context
        $inclContext = $logEntry->getMeta('inclContext');
        $inclArgs = $logEntry->getMeta('inclArgs');
        $backtrace = isset($logEntry['meta']['trace'])
            ? $logEntry['meta']['trace']
            : $this->backtrace->get($inclArgs ? \bdk\Backtrace::INCL_ARGS : 0);
        $logEntry->setMeta('trace', null);
        if ($backtrace && $inclContext) {
            $backtrace = $this->backtrace->addContext($backtrace);
            $this->addPlugin(new \bdk\Debug\Plugin\Highlight());
        }
        $logEntry['args'] = array($backtrace);
        $this->methodTable->onLog($logEntry);
        $this->appendLog($logEntry);
    }

    /**
     * Log a warning
     *
     * Supports styling & substitutions
     *
     * @param mixed $arg,... message / values
     *
     * @return void
     */
    public function warn()
    {
        $logEntry = new LogEntry(
            $this,
            __FUNCTION__,
            \func_get_args(),
            array(
                'detectFiles' => true,
                'uncollapse' => true,
            )
        );
        // file & line meta may already be set (ie coming via errorHandler)
        // file & line may also be defined as null
        $default = "\x00default\x00";
        if ($logEntry->getMeta('file', $default) === $default) {
            $callerInfo = $this->backtrace->getCallerInfo();
            $logEntry->setMeta(array(
                'file' => $callerInfo['file'],
                'line' => $callerInfo['line'],
            ));
        }
        $this->appendLog($logEntry);
    }

    /*
        "Non-Console" Methods
    */

    /**
     * Extend debug with a plugin
     *
     * @param AssetProviderInterface|SubscriberInterface $plugin object implementing SubscriberInterface and/or AssetProviderInterface
     *
     * @return $this
     * @throws InvalidArgumentException
     */
    public function addPlugin($plugin)
    {
        if ($this->registeredPlugins->contains($plugin)) {
            return $this;
        }
        $isPlugin = false;
        if ($plugin instanceof AssetProviderInterface) {
            $isPlugin = true;
            $this->readOnly['rootInstance']->getRoute('html')->addAssetProvider($plugin);
        }
        if ($plugin instanceof SubscriberInterface) {
            $isPlugin = true;
            $this->eventManager->addSubscriberInterface($plugin);
            $subscriptions = $plugin->getSubscriptions();
            if (isset($subscriptions[self::EVENT_PLUGIN_INIT])) {
                /*
                    plugin we just added subscribes to Debug::EVENT_PLUGIN_INIT
                    call subscriber directly
                */
                \call_user_func(
                    array($plugin, $subscriptions[self::EVENT_PLUGIN_INIT]),
                    new Event($this),
                    self::EVENT_PLUGIN_INIT,
                    $this->eventManager
                );
            }
        }
        if ($plugin instanceof RouteInterface) {
            $this->onCfgRoute($plugin);
        }
        if (!$isPlugin) {
            throw new InvalidArgumentException('addPlugin expects \\bdk\\Debug\\AssetProviderInterface and/or \\bdk\\PubSub\\SubscriberInterface');
        }
        $this->registeredPlugins->attach($plugin);
        return $this;
    }

    /**
     * Retrieve a configuration value
     *
     * @param string      $path what to get
     * @param null|string $opt  (@internal)
     *
     * @return mixed value
     */
    public function getCfg($path = null, $opt = null)
    {
        if ($path === 'route' && $this->cfg['route'] === 'auto') {
            return $this->internal->getDefaultRoute(); // returns string
        }
        if ($opt === self::CONFIG_DEBUG) {
            return $this->utility->arrayPathGet($this->cfg, $path);
        }
        return $this->config->get($path, $opt === self::CONFIG_INIT);
    }

    /**
     * Return a named subinstance... if channel does not exist, it will be created
     *
     * Channels can be used to categorize log data... for example, may have a framework channel, database channel, library-x channel, etc
     * Channels may have subchannels
     *
     * @param string $name   channel name
     * @param array  $config channel specific configuration
     *
     * @return static new or existing `Debug` instance
     */
    public function getChannel($name, $config = array())
    {
        /*
            Split on "."
            Split on "/" not adjacent to whitespace
        */
        $names = \preg_split('#(\.|(?<!\s)/(?!\s))#', $name);
        $cur = $this;
        while ($names) {
            $name = \array_shift($names);
            $conf = $config;
            if (!isset($cur->channels[$name])) {
                $conf = \array_merge(
                    array('nested' => true),
                    $conf,
                    isset($cur->cfg['channels'][$name])
                        ? $cur->cfg['channels'][$name]
                        : array()
                );
                $cfg = $cur->getCfg(null, self::CONFIG_INIT);
                $cfg = $cur->internal->getPropagateValues($cfg);
                // set channel values
                $cfg['debug']['channelIcon'] = null;
                $cfg['debug']['channelName'] = $conf['nested'] || $cur->readOnly['parentInstance']
                    ? $cur->cfg['channelName'] . '.' . $name
                    : $name;
                $cfg['debug']['parent'] = $cur;
                unset($conf['nested']);
                // instantiate channel
                $cur->channels[$name] = new static($cfg);
            }
            unset($conf['nested']);
            if ($conf) {
                $cur->channels[$name]->setCfg($conf);
            }
            $cur = $this->channels[$name];
        }
        return $cur;
    }

    /**
     * Return array of channels
     *
     * If $allDescendants == true :  key = "fully qualified" channel name
     *
     * @param bool $allDescendants (false) include all descendants?
     * @param bool $inclTop        (false) whether to incl topmost channels (ie "tabs")
     *
     * @return static[] Does not include self
     */
    public function getChannels($allDescendants = false, $inclTop = false)
    {
        $channels = $this->channels;
        if ($allDescendants) {
            $channels = array();
            foreach ($this->channels as $channel) {
                $channels = \array_merge(
                    $channels,
                    array($channel->getCfg('channelName', self::CONFIG_DEBUG) => $channel),
                    $channel->getChannels(true)
                );
            }
        }
        if ($this === $this->readOnly['rootInstance']) {
            if ($inclTop) {
                return $channels;
            }
            $channelsTop = $this->getChannelsTop();
            $channels = \array_diff_key($channels, $channelsTop);
        }
        return $channels;
    }

    /**
     * Get the topmost channels (ie "tabs")
     *
     * @return static[]
     */
    public function getChannelsTop()
    {
        $channels = array(
            $this->cfg['channelName'] => $this,
        );
        if ($this->readOnly['parentInstance']) {
            return $channels;
        }
        foreach ($this->readOnly['rootInstance']->channels as $name => $channel) {
            $fqn = $channel->getCfg('channelName');
            if (\strpos($fqn, '.') === false) {
                $channels[$name] = $channel;
            }
        }
        return $channels;
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
        if (!$path) {
            $data = $this->utility->arrayCopy($this->data, false);
            $data['logSummary'] = $this->utility->arrayCopy($data['logSummary'], false);
            $data['groupStacks'] = $this->utility->arrayCopy($data['groupStacks'], false);
            return $data;
        }
        $data = $this->utility->arrayPathGet($this->data, $path);
        return \is_array($data) && \in_array($path, array('logSummary','groupStacks'))
            ? $this->utility->arrayCopy($data, false)
            : $data;
    }

    /**
     * Get dumper
     *
     * @param string $name      classname
     * @param bool   $checkOnly (false) only check if initialized
     *
     * @return \bdk\Debug\Dump\Base|bool
     *
     * @psalm-return ($checkOnly is true ? bool : \bdk\Debug\Dump\Base)
     */
    public function getDump($name, $checkOnly = false)
    {
        /** @var \bdk\Debug\Dump\Base|bool */
        return $this->getDumpRoute('dump', $name, $checkOnly);
    }

    /**
     * Get and clear headers that need to be output
     *
     * @return array headerName=>value array
     */
    public function getHeaders()
    {
        $headers = $this->data['headers'];
        $this->data['headers'] = array();
        return $headers;
    }

    /**
     * Returns the *Singleton* instance of this class.
     *
     * @param array $cfg optional config
     *
     * @return static
     */
    public static function getInstance($cfg = array())
    {
        if (!isset(self::$instance)) {
            // self::$instance set in __construct
            new static($cfg);
        } elseif ($cfg) {
            self::$instance->setCfg($cfg);
        }
        return self::$instance;
    }

    /**
     * Get route
     *
     * @param string $name      classname
     * @param bool   $checkOnly (false) only check if initialized
     *
     * @return RouteInterface|bool
     *
     * @psalm-return ($checkOnly is true ? bool : RouteInterface)
     */
    public function getRoute($name, $checkOnly = false)
    {
        /** @var RouteInterface|bool */
        return $this->getDumpRoute('route', $name, $checkOnly);
    }

    /**
     * "metafy" value/values
     *
     * accepts
     *  * `array('key'=>value)`
     *  * 'cfg', option, value  (shortcut for setting single config value)
     *  * 'key', value
     *  * 'key'                 (value defaults to true)
     *
     * @param mixed $args,... arguments
     *
     * @return array special array storing "meta" values
     */
    public static function meta()
    {
        $args = \func_get_args();
        $count = \count($args);
        /** @var mixed[] make psalm happy */
        $args = \array_replace(array(null, null, null), $args);
        if (\is_array($args[0])) {
            $args[0]['debug'] = self::META;
            return $args[0];
        }
        if (!\is_string($args[0])) {
            return array('debug' => self::META);
        }
        if ($args[0] === 'cfg') {
            if (\is_array($args[1])) {
                return array(
                    'cfg' => $args[1],
                    'debug' => self::META,
                );
            }
            if (!\is_string($args[1])) {
                // invalid cfg key
                return array('debug' => self::META);
            }
            return array(
                'cfg' => array(
                    $args[1] => $count > 2
                        ? $args[2]
                        : true,
                ),
                'debug' => self::META,
            );
        }
        return array(
            $args[0] => $count > 1
                ? $args[1]
                : true,
            'debug' => self::META,
        );
    }

    /**
     * Debug::EVENT_CONFIG event listener
     *
     * Since setCfg() passes config through Config, we need a way for Config to pass values back.
     *
     * @param Event $event Debug::EVENT_CONFIG Event instance
     *
     * @return void
     */
    public function onConfig(Event $event)
    {
        $cfg = $event['debug'];
        if (!$cfg) {
            return;
        }
        $valActions = array(
            'route' => array($this, 'onCfgRoute'),
            'logEnvInfo' => array($this, 'onCfgList'),
            'logRequestInfo' => array($this, 'onCfgList'),
            'logServerKeys' => function ($val) {
                // don't append, replace
                $this->cfg['logServerKeys'] = array();
                return $val;
            },
        );
        foreach ($valActions as $key => $callable) {
            if (isset($cfg[$key])) {
                /** @psalm-suppress TooManyArguments */
                $cfg[$key] = $callable($cfg[$key], $key);
            }
        }
        $this->cfg = $this->utility->arrayMergeDeep($this->cfg, $cfg);
        /*
            propagate updated vals to child channels
        */
        $channels = $this->getChannels(false, true);
        if ($channels) {
            $cfg = $this->internal->getPropagateValues($event->getValues());
            foreach ($channels as $channel) {
                $channel->config->set($cfg);
            }
        }
    }

    /**
     * Return debug log output
     *
     * Publishes Debug::EVENT_OUTPUT event and returns event's 'return' value
     *
     * @param array $cfg Override any config values
     *
     * @return string|null
     */
    public function output($cfg = array())
    {
        $cfgRestore = $this->config->set($cfg);
        if (!$this->cfg['output']) {
            $this->config->set($cfgRestore);
            return null;
        }
        $route = $this->getCfg('route');
        if (\is_string($route)) {
            // Internal::onConfig will convert to route object
            $this->config->set('route', $route);
        }
        /*
            Publish Debug::EVENT_OUTPUT on all descendant channels and then ourself
            This isn't outputing each channel, but for performing any per-channel "before output" activities
        */
        $channels = $this->getChannels(true);
        $channels[] = $this;
        foreach ($channels as $channel) {
            $event = $channel->eventManager->publish(
                self::EVENT_OUTPUT,
                $channel,
                array(
                    'headers' => array(),
                    'isTarget' => $channel === $this,
                    'return' => '',
                )
            );
        }
        if (!$this->readOnly['parentInstance']) {
            $this->data['outputSent'] = true;
        }
        $this->config->set($cfgRestore);
        return $event['return'];
    }

    /**
     * Remove plugin
     *
     * @param SubscriberInterface $plugin object implementing SubscriberInterface
     *
     * @return $this
     */
    public function removePlugin(SubscriberInterface $plugin)
    {
        $this->registeredPlugins->detach($plugin);
        if ($plugin instanceof AssetProviderInterface) {
            $this->readOnly['rootInstance']->getRoute('html')->removeAssetProvider($plugin);
        }
        if ($plugin instanceof SubscriberInterface) {
            $this->eventManager->RemoveSubscriberInterface($plugin);
        }
        return $this;
    }

    /**
     * Set one or more config values
     *
     * `setCfg('key', 'value')`
     * `setCfg('level1.level2', 'value')`
     * `setCfg(array('k1'=>'v1', 'k2'=>'v2'))`
     *
     * @param string|array $path  path
     * @param mixed        $value value
     *
     * @return mixed previous value(s)
     */
    public function setCfg($path, $value = null)
    {
        return $this->config->set($path, $value);
    }

    /**
     * Advanced usage
     *
     *    setCfg('key', 'value')
     *    setCfg('level1.level2', 'value')
     *    setCfg(array('k1'=>'v1', 'k2'=>'v2'))
     *
     * @param string|array $path  path or array of values to merge
     * @param mixed        $value value
     *
     * @return void
     */
    public function setData($path, $value = null)
    {
        $this->data = \is_array($path)
            ? \array_merge($this->data, $path)
            : \call_user_func(function ($path, $value) {
                $this->utility->arrayPathSet($this->data, $path, $value);
                return $this->data;
            }, $path, $value);
        if (!$this->data['log']) {
            $this->data['groupStacks']['main'] = array();
        }
        if (!$this->data['logSummary']) {
            $this->data['groupStacks'] = \array_intersect_key(
                $this->data['groupStacks'],
                array('main' => true)
            );
            $this->data['groupPriorityStack'] = array();
        }
        $this->setLogDest();
    }

    /**
     * A wrapper for errorHandler->setErrorCaller
     *
     * @param array $caller (optional) null (default) determine automatically
     *                      empty value (false, "", 0, array()) clear
     *                      array manually set
     *
     * @return void
     */
    public function setErrorCaller($caller = null)
    {
        if ($caller === null) {
            $caller = $this->backtrace->getCallerInfo(1);
            $caller = array(
                'file' => $caller['file'],
                'line' => $caller['line'],
            );
        }
        if ($caller) {
            // groupEnd will check depth and potentially clear errorCaller
            $caller['groupDepth'] = $this->getGroupDepth();
        }
        $this->errorHandler->setErrorCaller($caller);
    }

    /**
     * Appends debug output (if applicable) and/or adds headers (if applicable)
     *
     * You should call this at the end of the request/response cycle in your PSR-7 project,
     * e.g. immediately before emitting the Response.
     *
     * @param ResponseInterface|HttpFoundationResponse $response PSR-7 or HttpFoundation response
     *
     * @return ResponseInterface|HttpFoundationResponse
     *
     * @throws InvalidArgumentException
     */
    public function writeToResponse($response)
    {
        if ($response instanceof ResponseInterface) {
            $this->cfg['services']['response'] = $response;
            $this->cfg['outputHeaders'] = false;
            $debugOutput = $this->output();
            if ($debugOutput) {
                $stream = $response->getBody();
                $stream->seek(0, SEEK_END);
                $stream->write($debugOutput);
                $stream->rewind();
            }
            $headers = $this->getHeaders();
            foreach ($headers as $nameVal) {
                $response = $response->withHeader($nameVal[0], $nameVal[1]);
            }
            return $response;
        }
        if ($response instanceof HttpFoundationResponse) {
            $this->cfg['services']['response'] = HttpFoundationBridge::createResponse($response);
            $this->cfg['outputHeaders'] = false;
            $content = $response->getContent();
            $pos = \strripos($content, '</body>');
            if ($pos !== false) {
                $content = \substr($content, 0, $pos)
                    . $this->output()
                    . \substr($content, $pos);
                $response->setContent($content);
                // reset the content length
                $response->headers->remove('Content-Length');
            }
            $headers = $this->getHeaders();
            foreach ($headers as $nameVal) {
                $response = $response->headers->set($nameVal[0], $nameVal[1]);
            }
            return $response;
        }
        throw new InvalidArgumentException(\sprintf(
            'writeToResponse expects ResponseInterface or HttpFoundationResponse, but %s provided',
            \is_object($response) ? \get_class($response) : \gettype($response)
        ));
    }

    /*
        Non-Public methods
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
        $className = \ltrim($className, '\\'); // leading backslash _shouldn't_ have been passed
        $psr4Map = array(
            'bdk\\Debug\\' => __DIR__,
            'bdk\\PubSub\\' => __DIR__ . '/../PubSub',
            'bdk\\ErrorHandler\\' => __DIR__ . '/../ErrorHandler',
        );
        $classMap = array(
            'bdk\\Backtrace' => __DIR__ . '/../Backtrace/Backtrace.php',
            'bdk\\Debug\\Utility' => __DIR__ . '/Utility/Utility.php',
            'bdk\\ErrorHandler' => __DIR__ . '/../ErrorHandler/ErrorHandler.php',
        );
        if (isset($classMap[$className])) {
            require $classMap[$className];
            return;
        }
        foreach ($psr4Map as $namespace => $dir) {
            if (\strpos($className, $namespace) === 0) {
                $rel = \substr($className, \strlen($namespace));
                $rel = \str_replace('\\', '/', $rel);
                require $dir . '/' . $rel . '.php';
                return;
            }
        }
    }

    /**
     * Store the arguments
     * if collect is false -> does nothing
     * otherwise:
     *   + abstracts values
     *   + publishes Debug::EVENT_LOG event
     *   + appends log (if event propagation not stopped)
     *
     * @param LogEntry $logEntry     log entry instance
     * @param bool     $forcePublish (false) publish event event if collect is false
     *
     * @return bool whether or not entry got appended
     */
    protected function appendLog(LogEntry $logEntry, $forcePublish = false)
    {
        if (!$this->cfg['collect'] && !$forcePublish) {
            return false;
        }
        $cfgRestore = array();
        if (isset($logEntry['meta']['cfg'])) {
            $cfgRestore = $this->config->set($logEntry['meta']['cfg']);
            $logEntry->setMeta('cfg', null);
        }
        if (\count($logEntry['args']) === 1 && $this->utility->isThrowable($logEntry['args'][0])) {
            $exception = $logEntry['args'][0];
            $logEntry['args'][0] = $exception->getMessage();
            $logEntry->setMeta(array(
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->backtrace->get(null, 0, $exception),
            ));
        }
        foreach ($logEntry['args'] as $i => $v) {
            $logEntry['args'][$i] = $this->abstracter->crate($v, $logEntry['method']);
        }
        $this->internal->publishBubbleEvent(self::EVENT_LOG, $logEntry);
        if ($cfgRestore) {
            $this->config->set($cfgRestore);
        }
        if ($logEntry['appendLog']) {
            $this->readOnly['rootInstance']->logRef[] = $logEntry;
            return true;
        }
        return false;
    }

    /**
     * Initialize autoloader, container, & config
     *
     * @param array $cfg passed cfg
     *
     * @return void
     */
    private function bootstrap($cfg)
    {
        if (!isset(self::$instance)) {
            /*
               self::getInstance() will always return initial/first instance
            */
            self::$instance = $this;
            /*
                Only register autoloader:
                  a. on initial instance (even though re-registering function does't re-register)
                  b. if we're unable to to find our Config class (must not be using Composer)
            */
            if (\class_exists('bdk\\Debug\\Config') === false) {
                \spl_autoload_register(array($this, 'autoloader'));
            }
        }
        $this->getViaContainer('errorHandler');
        $this->registeredPlugins = new SplObjectStorage();
        $this->config = $this->getViaContainer('config');
        $this->eventManager->subscribe(self::EVENT_CONFIG, array($this, 'onConfig'));
        $this->config->set($cfg);
        $this->bootstrapInstance();
        /*
            Initialize Internal
        */
        $this->internal = $this->getViaContainer('internal');
        $this->internal->init();
        $this->data['requestId'] = $this->internal->requestId();
        if ($this->cfg['emailTo'] === 'default') {
            $this->cfg['emailTo'] = $this->internal->getServerParam('SERVER_ADMIN');
        }
        $this->internalEvents = $this->getViaContainer('internalEvents');
        $this->eventManager->publish(self::EVENT_BOOTSTRAP, $this);
    }

    /**
     * Set instance, rootInstance, parentInstance, & initialize data
     *
     * @return void
     */
    private function bootstrapInstance()
    {
        $this->readOnly['rootInstance'] = $this;
        if (isset($this->cfg['parent'])) {
            $this->readOnly['parentInstance'] = $this->cfg['parent'];
            while ($this->readOnly['rootInstance']->readOnly['parentInstance']) {
                $this->readOnly['rootInstance'] = $this->readOnly['rootInstance']->readOnly['parentInstance'];
            }
            $this->data = &$this->readOnly['rootInstance']->data;
            unset($this->cfg['parent']);
            return;
        }
        // this is the root instance
        $this->setLogDest();
        $this->data['entryCountInitial'] = \count($this->data['log']);
    }

    /**
     * Append group or groupCollapsed to log
     *
     * @param string $method 'group' or 'groupCollapsed'
     * @param array  $args   arguments passed to group or groupCollapsed
     *
     * @return void
     */
    private function doGroup($method, $args)
    {
        $logEntry = new LogEntry(
            $this,
            $method,
            $args
        );
        $this->readOnly['rootInstance']->groupStackRef[] = array(
            'channel' => $this,
            'collect' => $this->cfg['collect'],
        );
        if ($this->cfg['collect'] === false) {
            return;
        }
        $this->internal->doGroup($logEntry);
    }

    /**
     * Calculate total group depth
     *
     * @return int
     */
    protected function getGroupDepth()
    {
        $depth = 0;
        foreach ($this->data['groupStacks'] as $stack) {
            $depth += \count($stack);
        }
        $depth += \count($this->data['groupPriorityStack']);
        return $depth;
    }

    /**
     * Set "container" factories
     *
     * @return array
     */
    private function getDefaultFactories()
    {
        return array(
            'methodProfile' => function () {
                return new Debug\Method\Profile();
            },
        );
    }

    /**
     * Set "container" services
     *
     * @return array
     */
    private function getDefaultServices()
    {
        return array(
            'abstracter' => function (Debug $debug) {
                return new Abstracter($debug, $debug->config->get('abstracter', self::CONFIG_INIT));
            },
            'backtrace' => function () {
                $backtrace = $this->errorHandler->backtrace;
                $backtrace->addInternalClass('bdk\\Debug');
                return $backtrace;
            },
            'config' => function (Debug $debug) {
                return new \bdk\Debug\Config($debug);    // cfg is passed by reference
            },
            'errorEmailer' => function (Debug $debug) {
                return new \bdk\ErrorHandler\ErrorEmailer($debug->config->get('errorEmailer', self::CONFIG_INIT));
            },
            'errorHandler' => function (Debug $debug) {
                $existingInstance = \bdk\ErrorHandler::getInstance();
                if ($existingInstance) {
                    return $existingInstance;
                }
                $errorHandler = new \bdk\ErrorHandler($debug->eventManager);
                /*
                    log E_USER_ERROR to system_log without halting script
                */
                $errorHandler->setCfg('onEUserError', 'log');
                return $errorHandler;
            },
            'eventManager' => function () {
                return new \bdk\PubSub\Manager();
            },
            'html' => function () {
                return new \bdk\Debug\Utility\Html();
            },
            'internal' => function (Debug $debug) {
                return new \bdk\Debug\Internal($debug);
            },
            'internalEvents' => function (Debug $debug) {
                return new \bdk\Debug\InternalEvents($debug);
            },
            'logEnv' => function () {
                return new \bdk\Debug\Plugin\LogEnv();
            },
            'logFiles' => function (Debug $debug) {
                return new \bdk\Debug\Plugin\LogFiles(
                    $debug->config->get('logFiles', self::CONFIG_INIT),
                    $debug
                );
            },
            'logReqRes' => function () {
                return new \bdk\Debug\Plugin\LogReqRes();
            },
            'logger' => function (Debug $debug) {
                return new \bdk\Debug\Psr3\Logger($debug);
            },
            'methodClear' => function () {
                return new \bdk\Debug\Method\Clear();
            },
            'methodTable' => function () {
                return new \bdk\Debug\Method\Table();
            },
            'middleware' => function (Debug $debug) {
                return new \bdk\Debug\Psr15\Middleware($debug);
            },
            'request' => function () {
                /*
                    This can return Psr\Http\Message\ServerRequestInterface
                */
                return \bdk\Debug\Psr7lite\ServerRequest::fromGlobals();
            },
            'response' => null,
            'stopWatch' => function () {
                return new \bdk\Debug\Utility\StopWatch(array(
                    'requestTime' => $this->internal->getServerParam('REQUEST_TIME_FLOAT'),
                ));
            },
            'utf8' => function () {
                return new \bdk\Debug\Utility\Utf8();
            },
            'utility' => function () {
                return new \bdk\Debug\Utility();
            },
        );
    }

    /**
     * Get Dump or Route instance
     *
     * @param 'dump'|'route' $cat       "Category" (dump or route)
     * @param string         $name      html, text, etc)
     * @param bool           $checkOnly Only check if initialized?
     *
     * @return \bdk\Debug\Dump\Base|RouteInterface|bool
     *
     * @psalm-return ($checkOnly is true ? bool : \bdk\Debug\Dump\Base|RouteInterface)
     */
    private function getDumpRoute($cat, $name, $checkOnly)
    {
        $property = $cat . \ucfirst($name);
        $isset = isset($this->readOnly[$property]);
        if ($checkOnly) {
            return $isset;
        }
        if ($isset) {
            return $this->readOnly[$property];
        }
        $val = $this->getViaContainer($property);
        if ($val !== false) {
            return $val;
        }
        $classname = 'bdk\\Debug\\' . \ucfirst($cat) . '\\' . \ucfirst($name);
        if (\class_exists($classname)) {
            /** @var \bdk\Debug\Dump\Base|RouteInterface */
            $val = new $classname($this);
            if ($val instanceof ConfigurableInterface) {
                $cfg = $this->config->get($property, self::CONFIG_INIT);
                $val->setCfg($cfg);
            }
            $this->readOnly[$property] = $val;
            return $val;
        }
        $caller = $this->backtrace->getCallerInfo();
        $this->errorHandler->handleError(
            E_USER_NOTICE,
            '"' . $property . '" is not accessible',
            $caller['file'],
            $caller['line']
        );
    }

    /**
     * Get Method's default argument list
     *
     * @param string $methodName Name of the method
     *
     * @return array
     */
    private static function getMethodDefaultArgs($methodName)
    {
        $defaultArgs = array();
        if (isset(self::$methodDefaultArgs[$methodName])) {
            $defaultArgs = self::$methodDefaultArgs[$methodName];
        } elseif (\method_exists(self::$instance, $methodName)) {
            $reflectionMethod = new ReflectionMethod(self::$instance, $methodName);
            $params = $reflectionMethod->getParameters();
            foreach ($params as $reflectionParameter) {
                $defaultArgs[] = $reflectionParameter->isOptional()
                    ? $reflectionParameter->getDefaultValue()
                    : null;
            }
            self::$methodDefaultArgs[$methodName] = $defaultArgs;
        }
        return $defaultArgs;
    }

    /**
     * Check container for property
     *
     * @param string $property service/factory name
     *
     * @return mixed (false if doesn't exist)
     */
    private function getViaContainer($property)
    {
        $val = false;
        $isNew = false;
        if (\array_key_exists($property, $this->cfg['services'])) {
            $val = $this->cfg['services'][$property];
            if ($val instanceof \Closure) {
                $isNew = true;
                $val = $val($this);
                $this->cfg['services'][$property] = $val;
            }
        } elseif (isset($this->cfg['factories'][$property])) {
            $val = $this->cfg['factories'][$property];
            if ($val instanceof \Closure) {
                $isNew = true;
                $val = $val($this);
            }
        }
        if ($isNew && $val instanceof ConfigurableInterface) {
            $val->setCfg($this->config->get($property, self::CONFIG_INIT));
        }
        return $val;
    }

    /**
     * Are we inside a group?
     *
     * @return int 2: group summary, 1: regular group, 0: not in group
     */
    private function haveOpenGroup()
    {
        $groupStackWas = $this->readOnly['rootInstance']->groupStackRef;
        if ($this->data['groupPriorityStack'] && !$groupStackWas) {
            // we're in top level of group summary
            return 2;
        }
        if ($groupStackWas && \end($groupStackWas)['collect'] === $this->cfg['collect']) {
            return 1;
        }
        return 0;
    }

    /**
     * Convert logEnvInfo & logRequestInfo values to key=>value arrays
     *
     * @param mixed  $val  value
     * @param string $name 'logEnvInfo'|'logRequestInfo'
     *
     * @return array
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgList($val, $name)
    {
        $allKeys = \array_keys($this->cfg[$name]);
        if (\is_bool($val)) {
            $val = \array_fill_keys($allKeys, $val);
        } elseif ($this->utility->arrayIsList($val)) {
            $val = \array_merge(
                \array_fill_keys($allKeys, false),
                \array_fill_keys($val, true)
            );
        }
        return $val;
    }

    /**
     * If "core" route, store in readOnly property
     *
     * @param mixed $val route value
     *
     * @return mixed
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod)
     */
    private function onCfgRoute($val)
    {
        if ($val instanceof RouteInterface) {
            $classname = \get_class($val);
            $prefix = __NAMESPACE__ . '\\Debug\\Route\\';
            if (\strpos($classname, $prefix) === 0) {
                $prop = 'route' . \substr($classname, \strlen($prefix));
                $this->readOnly[$prop] = $val;
            }
        }
        return $val;
    }

    /**
     * Set where appendLog appends to
     *
     * @param string $where ('auto'), 'alerts', log', 'summary'
     *
     * @return void
     */
    private function setLogDest($where = 'auto')
    {
        if ($where === 'auto') {
            $where = $this->data['groupPriorityStack']
                ? 'summary'
                : 'log';
        }
        switch ($where) {
            case 'alerts':
                $this->readOnly['rootInstance']->logRef = &$this->readOnly['rootInstance']->data['alerts'];
                break;
            case 'log':
                $this->readOnly['rootInstance']->logRef = &$this->readOnly['rootInstance']->data['log'];
                $this->readOnly['rootInstance']->groupStackRef = &$this->readOnly['rootInstance']->data['groupStacks']['main'];
                break;
            case 'summary':
                $priority = \end($this->data['groupPriorityStack']);
                if (!isset($this->data['logSummary'][$priority])) {
                    $this->data['logSummary'][$priority] = array();
                    $this->data['groupStacks'][$priority] = array();
                }
                $this->readOnly['rootInstance']->logRef = &$this->readOnly['rootInstance']->data['logSummary'][$priority];
                $this->readOnly['rootInstance']->groupStackRef = &$this->readOnly['rootInstance']->data['groupStacks'][$priority];
        }
    }

    /**
     * Create timeEnd & timeGet LogEntry
     *
     * @param string $method 'timeEnd' or 'timeGet'
     * @param array  $args   arguments passed to method
     *
     * @return LogEntry
     */
    private function timeLogEntry($method, $args)
    {
        $logEntry = new LogEntry(
            $this,
            $method,
            $args,
            array(
                'precision' => 4,
                'silent' => false,
                'template' => '%label: %time',
                'unit' => 'auto',
            ),
            array(
                'label' => null,
                'log' => true,
            )
        );
        $numArgs = $logEntry['numArgs'];
        $args = $logEntry['args'];
        $label = $args[0];
        $log = $args[1];
        if ($numArgs === 1 && \is_bool($label)) {
            // $log passed as single arg
            $logEntry->setMeta('silent', !$label);
            $args[0] = null;
            $logEntry['args'] = $args;
        } elseif ($numArgs === 2) {
            $logEntry->setMeta('silent', !$log);
        }
        return $logEntry;
    }
}
