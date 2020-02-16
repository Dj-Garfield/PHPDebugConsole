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

namespace bdk\Debug\Collector;

use bdk\Debug;
use bdk\Debug\Collector\MySqli\MySqliStmt;
use bdk\Debug\Collector\StatementInfo;
use bdk\Debug\Plugin\Highlight;
use bdk\PubSub\Event;
use Exception;
use mysqli as mysqliBase;
use RuntimeException;

/**
 * mysqli extended with debugging
 */
class MySqli extends mysqliBase
{

    public $connectionAttempted = false;
    protected $icon = 'fa fa-database';
    protected $loggedStatements = array();
    private $debug;

    /**
     * Constructor
     *
     * @param string $host     host name or IP
     * @param string $username MySQL user name
     * @param string $passwd   password
     * @param string $dbname   default database used wiehn performing queries
     * @param int    $port     port number
     * @param string $socket   socket or named pipe that should be used
     * @param Debug  $debug    (optional) Specify PHPDebugConsole instance
     *                           if not passed, will create MySqli channnel on singleton instance
     *                           if root channel is specified, will create a MySqli channel
     */
    public function __construct($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null, Debug $debug = null)
    {
        $numArgs = \func_num_args();
        if ($numArgs) {
            $paramsDefault = array(
                'host' => \ini_get('mysqli.default_host'),
                'username' => \ini_get('mysqli.default_user'),
                'passwd' => \ini_get('mysqli.default_pw'),
                'dbname' => '',
                'port' => \ini_get('mysqli.default_port'),
                'socket' => \ini_get('mysqli.default_socket'),
            );
            $params = array(
                'host' => $host,
                'username' => $username,
                'passwd' => $passwd,
                'dbname' => $dbname,
                'port' => $port,
                'socket' => $socket,
            );
            foreach ($params as $k => $v) {
                if ($v === null) {
                    $params[$k] = $paramsDefault[$k];
                }
            }
            $this->connectionAttempted = true;
            parent::__construct(
                $params['host'],
                $params['username'],
                $params['passwd'],
                $params['dbname'],
                $params['port'],
                $params['socket']
            );
        } else {
            /*
                Calling the constructor with no parameters is the same as calling mysqli_init().
            */
            parent::__construct();
        }
        if (!$debug) {
            $debug = Debug::_getChannel('MySqli', array('channelIcon' => $this->icon));
        } elseif ($debug === $debug->rootInstance) {
            $debug = $debug->getChannel('MySqli', array('channelIcon' => $this->icon));
        }
        $this->debug = $debug;
        $this->debug->eventManager->subscribe('debug.output', array($this, 'onDebugOutput'), 1);
        $this->debug->addPlugin(new Highlight());
    }

    /**
     * Logs StatementInfo
     *
     * @param StatementInfo $info statement info instance
     *
     * @return void
     */
    public function addStatementInfo(StatementInfo $info)
    {
        $this->loggedStatements[] = $info;
        $info->appendLog($this->debug);
    }

    /**
     * Returns the accumulated execution time of statements
     *
     * @return float
     */
    public function getTimeSpent()
    {
        return \array_reduce($this->loggedStatements, function ($val, StatementInfo $info) {
            return $val + $info->duration;
        });
    }

    /**
     * Returns the peak memory usage while performing statements
     *
     * @return int
     */
    public function getPeakMemoryUsage()
    {
        return \array_reduce($this->loggedStatements, function ($carry, StatementInfo $info) {
            $mem = $info->memoryUsage;
            return $mem > $carry
                ? $mem
                : $carry;
        });
    }

    /**
     * Returns the list of executed statements as StatementInfo objects
     *
     * @return StatementInfo[]
     */
    public function getLoggedStatements()
    {
        return $this->loggedStatements;
    }

    /**
     * debug.output subscriber
     *
     * @param Event $event event instance
     *
     * @return void
     */
    public function onDebugOutput(Event $event)
    {
        $debug = $event->getSubject();
        $debug->groupSummary(0);
        \set_error_handler(function ($errno, $errstr) {
            throw new RuntimeException($errstr, $errno);
        }, E_ALL);
        try {
            $groupParams = array(
                'MySqli info',
                $this->host_info
            );
            $groupParams[] = $debug->meta(array(
                'argsAsParams' => false,
                'icon' => $this->icon,
                'level' => 'info',
            ));
            \call_user_func_array(array($debug, 'groupCollapsed'), $groupParams);

            $debug->log('logged operations: ', \count($this->loggedStatements));
            $debug->time('total time', $this->getTimeSpent());
            $debug->log('max memory usage', $debug->utilities->getBytes($this->getPeakMemoryUsage()));

            // parse server info
            \preg_match_all('#([^:]+): ([a-zA-Z0-9.]+)\s*#', $this->stat(), $matches);
            $serverInfo = \array_map(function ($val) {
                return $val * 1;
            }, \array_combine($matches[1], $matches[2]));
            $serverInfo['Version'] = $this->server_info;
            \ksort($serverInfo);
            $debug->log('server info', $serverInfo);

            $debug->groupEnd(); // groupCollapsed
        } catch (RuntimeException $e) {
            $debug->group('MySqli Error', $debug->meta(array('level' => 'error')));
            $debug->log('Connection Error');
        }
        \restore_error_handler();
        $debug->groupEnd();
        $debug->groupEnd(); // groupSummary
    }

    /**
     * {@inheritDoc}
     */
    public function multi_query($query)
    {
        return $this->profileCall('multi_query', $query, \func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function prepare($query)
    {
        return new MySqliStmt($this, $query);
    }

    /**
     * {@inheritDoc}
     */
    public function query($query, $resultmode = MYSQLI_STORE_RESULT)
    {
        return $this->profileCall('query', $query, array($query, $resultmode));
    }

    /**
     * {@inheritDoc}
     */
    public function real_connect($host = null, $username = null, $passwd = null, $dbname = null, $port = null, $socket = null, $flags = null)
    {
        $this->connectionAttempted = true;
        return parent::real_connect($host, $username, $passwd, $dbname, $port, $socket, $flags);
    }

    /**
     * {@inheritDoc}
     */
    public function real_query($query)
    {
        return $this->profileCall('real_query', $query, \func_get_args());
    }

    /**
     * {@inheritDoc}
     */
    public function stmt_init()
    {
        return new MySqliStmt($this, null);
    }

    /**
     * Profiles a call to a mysqli method
     *
     * @param string $method PDO method
     * @param string $sql    sql statement
     * @param array  $args   method args
     *
     * @return mixed The result of the call
     * @throws Exception
     */
    private function profileCall($method, $sql, array $args)
    {
        $info = new StatementInfo($sql);
        if ($this->connectionAttempted) {
            $return = \call_user_func_array(array('parent', $method), $args);
            $exception = !$return
                ? new Exception($this->error, $this->errno)
                : null;
            $affectedRows = $method !== 'multi_query' && $return
                ? $this->affected_rows
                : null;
        } else {
            $return = false;
            $exception = new Exception('Not connected');
            $affectedRows = null;
        }
        $info->end($exception, $affectedRows);
        $this->addStatementInfo($info);
        return $return;
    }
}
