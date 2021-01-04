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

namespace bdk\Debug\Framework\Yii2;

use bdk\Debug;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\Collector\Pdo;
use bdk\Debug\Framework\Yii2\LogTarget;
use bdk\ErrorHandler;
use bdk\ErrorHandler\Error;
use bdk\PubSub\Event;
use bdk\PubSub\Manager as EventManager;
use bdk\PubSub\SubscriberInterface;
use Yii;
use yii\base\BootstrapInterface;
use yii\base\Event as YiiEvent;
use yii\base\Model;
use yii\base\Module as BaseModule;

/**
 * PhpDebugBar Yii 2 Module
 */
class Module extends BaseModule implements SubscriberInterface, BootstrapInterface
{

    /** @var \bdk\Debug */
    public $debug;

    public $logTarget;

    private $app;
    private $collectedEvents = array();

    /**
     * Constructor
     *
     * @param string $id     the ID of this module.
     * @param Module $parent the parent module (if any).
     * @param array  $config name-value pairs that will be used to initialize the object properties.
     *
     * @phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter
     * @SuppressWarnings(PHPMD.StaticAccess)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function __construct($id, $parent, $config = array())
    {
        $this->app = $parent;
        $debugRootInstance = Debug::getInstance(array(
            'logEnvInfo' => array(
                'session' => false,
            ),
            'logFiles' => array(
                'filesExclude' => array(
                    '/framework/',
                    '/protected/components/system/',
                    '/vendor/',
                ),
            ),
            'yii' => array(
                'events' => true,
                'log' => true,
                'pdo' => true,
                'session' => true,
                'user' => true,
            ),
        ));
        $debugRootInstance->setCfg($config);
        $debugRootInstance->eventManager->addSubscriberInterface($this);
        /*
            Debug error handler may have been registered first -> reregister
        */
        $debugRootInstance->errorHandler->unregister();
        $debugRootInstance->errorHandler->register();
        $this->debug = $debugRootInstance->getChannel('Yii');
    }

    /**
     * Magic setter
     *
     * Allows us to specify config values in the debug component config array
     *
     * @param string $name  property name
     * @param mixed  $value property value
     *
     * @return void
     */
    public function __set($name, $value)
    {
        $cfg = $name === 'config'
            ? $value
            : array($name => $value);
        $this->debug->rootInstance->setCfg($cfg);
    }

    /**
     * {@inheritDoc}
     */
    public function bootstrap($app)
    {
        $this->app = $app;
        $this->collectEvent();
        $this->collectLog();
        $this->collectPdo();
        $this->logSession();
    }

    /**
     * {@inheritDoc}
     */
    public function getSubscriptions()
    {
        return array(
            Debug::EVENT_OBJ_ABSTRACT_END => 'onDebugObjAbstractEnd',
            Debug::EVENT_OUTPUT => array('onDebugOutput', 1),
            ErrorHandler::EVENT_ERROR => array(
                array('onErrorLow', -1),
                array('onErrorHigh', 1),
            ),
        );
    }

    /**
     * Debug::EVENT_OBJ_ABSTRACT_END event subscriber
     *
     * @param Abstraction $abs Abstraction instance
     *
     * @return void
     */
    public function onDebugObjAbstractEnd(Abstraction $abs)
    {
        if ($abs->getSubject() instanceof \yii\db\BaseActiveRecord) {
            $abs['properties']['_attributes']['forceShow'] = true;
        }
    }

    /**
     * PhpDebugConsole output event listener
     *
     * @param Event $event Event instance
     *
     * @return void
     */
    public function onDebugOutput(Event $event)
    {
        $this->logCollectedEvents();
        $this->logUser();
    }

    /**
     * Intercept minor framework issues and ignore them
     *
     * @param Error $error Error instance
     *
     * @return void
     */
    public function onErrorHigh(Error $error)
    {
        if (\in_array($error['category'], array(Error::CAT_DEPRECATED, Error::CAT_NOTICE, Error::CAT_STRICT))) {
            /*
                "Ignore" minor internal framework errors
            */
            if (\strpos($error['file'], YII2_PATH) === 0) {
                $error->stopPropagation();          // don't log it now
                $error['isSuppressed'] = true;
                $this->ignoredErrors[] = $error['hash'];
            }
        }
        if ($error['category'] !== Error::CAT_FATAL) {
            /*
                Don't pass error to Yii's handler... it will exit for #reasons
            */
            $error['continueToPrevHandler'] = false;
        }
    }

    /**
     * ErrorHandler::EVENT_ERROR event subscriber
     *
     * @param Error $error Error instance
     *
     * @return void
     */
    public function onErrorLow(Error $error)
    {
        // Yii's handler will log the error.. we can ignore that
        $this->logTarget->enabled = false;
        if ($error['exception']) {
            $this->app->handleException($error['exception']);
        } elseif ($error['category'] === Error::CAT_FATAL) {
            // Yii's error handler exits (for reasons)
            //    exit within shutdown procedure (that's us) = immediate exit
            //    so... unsubscribe the callables that have already been called and
            //    re-publish the shutdown event before calling yii's error handler
            foreach ($this->debug->rootInstance->eventManager->getSubscribers(EventManager::EVENT_PHP_SHUTDOWN) as $callable) {
                $this->debug->rootInstance->eventManager->unsubscribe(EventManager::EVENT_PHP_SHUTDOWN, $callable);
                if (\is_array($callable) && $callable[0] === $this->debug->rootInstance->errorHandler) {
                    break;
                }
            }
            $this->debug->rootInstance->eventManager->publish(EventManager::EVENT_PHP_SHUTDOWN);
            $this->app->handleError($error['type'], $error['message'], $error['file'], $error['line']);
        }
        $this->logTarget->enabled = true;
    }

    /**
     * Collect Yii events
     *
     * @return void
     */
    protected function collectEvent()
    {
        if ($this->shouldCollect('events') === false) {
            return;
        }
        $yiiVersion = $this->app->getVersion();
        if (!\version_compare($yiiVersion, '2.0.14', '>=')) {
            return;
        }
        YiiEvent::on('*', '*', function (YiiEvent $event) {
            $this->collectedEvents[] = array(
                'index' => \count($this->collectedEvents),
                // 'time' => \microtime(true),
                'senderClass' => \is_object($event->sender)
                    ? \get_class($event->sender)
                    : $event->sender,
                'name' => $event->name,
                'eventClass' => \get_class($event),
                'isStatic' => \is_object($event->sender) === false,
            );
        });
    }

    /**
     * Collect Yii log messages
     *
     * @return void
     */
    protected function collectLog()
    {
        if ($this->shouldCollect('log') === false) {
            return;
        }
        $this->logTarget = new LogTarget($this->debug);
        $log = $this->app->getLog();
        $log->flushInterval = 1;
        $log->targets['phpDebugConsole'] = $this->logTarget;
    }

    /**
     * Collect PDO queries
     *
     * @return void
     */
    protected function collectPdo()
    {
        if ($this->shouldCollect('pdo') === false) {
            return;
        }
        YiiEvent::on('yii\\db\\Connection', 'afterOpen', function (YiiEvent $event) {
            $connection = $event->sender;
            $pdo = $connection->pdo;
            if ($pdo instanceof Pdo) {
                // already wrapped
                return;
            }
            $channelName = 'PDO';
            $pdoChannel = $this->debug->getChannel($channelName, array(
                'channelIcon' => 'fa fa-database',
                'channelShow' => false,
            ));
            $connection->pdo = new Pdo($pdo, $pdoChannel);
        });
    }

    /**
     * Output collected event info
     *
     * @return void
     */
    protected function logCollectedEvents()
    {
        $tableData = array();
        foreach ($this->collectedEvents as $info) {
            $key = $info['senderClass'] . $info['name'];
            if (isset($tableData[$key])) {
                $tableData[$key]['count']++;
                continue;
            }
            $info['count'] = 1;
            $tableData[$key] = $info;
        }

        \usort($tableData, function ($infoA, $infoB) {
            $cmp = \strcmp($infoA['senderClass'], $infoB['senderClass']);
            if ($cmp) {
                return $cmp;
            }
            return $infoA['index'] - $infoB['index'];
        });

        foreach ($tableData as &$info) {
            unset($info['index']);
            $info['senderClass'] = $this->debug->abstracter->crateWithVals($info['senderClass'], array(
                'typeMore' => 'classname',
            ));
            $info['eventClass'] = $this->debug->abstracter->crateWithVals($info['eventClass'], array(
                'typeMore' => 'classname',
            ));
        }

        $channelOpts = array(
            'channelIcon' => 'fa fa-bell-o',
            // 'channelSort' => -10,
            'nested' => false,
        );
        $debug = $this->debug->rootInstance->getChannel('events', $channelOpts);
        $debug->table(\array_values($tableData));
    }

    /**
     * Log session information
     *
     * @return void
     */
    protected function logSession()
    {
        if ($this->shouldCollect('session') === false) {
            return;
        }

        $session = $this->app->session;
        $session->open();

        $channelOpts = array(
            'channelIcon' => 'fa fa-suitcase',
            'nested' => false,
        );
        $debug = $this->debug->rootInstance->getChannel('Session', $channelOpts);

        $debug->log('session id', $session->id);
        $debug->log('session name', $session->name);
        $debug->log('session class', $debug->abstracter->crateWithVals(
            \get_class($session),
            array(
                'typeMore' => 'classname',
            )
        ));

        $sessionVals = array();
        foreach ($session as $k => $v) {
            $sessionVals[$k] = $v;
        }
        \ksort($sessionVals);
        $debug->log($sessionVals);
    }

    /**
     * Log current user info
     *
     * @return void
     */
    protected function logUser()
    {
        if ($this->shouldCollect('user') === false) {
            return;
        }

        $user = Yii::$app->get('user', false);
        if ($user->isGuest) {
            return;
        }

        $channelOpts = array(
            'channelIcon' => 'fa fa-user-o',
            'nested' => false,
        );
        $debug = $this->debug->rootInstance->getChannel('User', $channelOpts);

        $identityData = $user->identity->attributes;
        if ($user->identity instanceof Model) {
            $identityData = array();
            foreach ($user->identity->attributes as $key => $val) {
                $key = $user->identity->getAttributeLabel($key);
                $identityData[$key] = $val;
            }
        }
        $debug->table($identityData);

        try {
            $authManager = Yii::$app->getAuthManager();

            if ($authManager instanceof \yii\rbac\ManagerInterface) {
                $roles = \array_map(function ($role) {
                    return \get_object_vars($role);
                }, $authManager->getRolesByUser($user->id));
                $debug->table('roles', $roles, array(
                    'name',
                    'description',
                    'ruleName',
                    'data',
                    'createdAt',
                    'updatedAt'
                ));

                $permissions = \array_map(function ($permission) {
                    return \get_object_vars($permission);
                }, $authManager->getPermissionsByUser($user->id));
                $debug->table('permissions', $permissions, array(
                    'name',
                    'description',
                    'ruleName',
                    'data',
                    'createdAt:datetime',
                    'updatedAt:datetime'
                ));
            }
        } catch (\Exception $e) {
            $debug->error('Exception logging user info', $e);
        }
    }

    /**
     * Config get wrapper
     *
     * @param string $name    option name
     * @param mixed  $default default vale
     *
     * @return bool
     */
    protected function shouldCollect($name, $default = false)
    {
        $val = $this->debug->rootInstance->getCfg('yii.' . $name);
        return $val !== null
            ? $val
            : $default;
    }
}
