<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2019 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Collector;

use bdk\Debug;
use bdk\Debug\Collector\StatementInfo;
use bdk\Debug\Plugin\Prism;
use bdk\PubSub\Event;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Logging\SQLLogger;

/**
 * Log Doctrine queries
 *
 * http://doctrine-project.org
 */
class DoctrineLogger implements SQLLogger
{

    private $connection;
    private $debug;

    protected $debugStack;
    protected $icon = 'fa fa-database';

    protected $loggedStatements = array();
    protected $statementInfo;

    /**
     * Constructor
     *
     * @param Connection $connection Optional Doctrine DBAL connection instance
     *                                  pass to log connection info
     * @param Debug      $debug      Optional DebugInstance
     *
     * @throws Exception
     */
    public function __construct(Connection $connection = null, Debug $debug = null)
    {
        if (!$debug) {
            $debug = Debug::_getChannel('Doctrine', array('channelIcon' => $this->icon));
        } elseif ($debug === $debug->rootInstance) {
            $debug = $debug->getChannel('Doctrine', array('channelIcon' => $this->icon));
        }
        $this->connection = $connection;
        $this->debug = $debug;
        $this->debug->eventManager->subscribe('debug.output', array($this, 'onDebugOutput'), 1);
        $this->debug->addPlugin(new Prism());
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
     * @return integer
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
     * debug.output subscriber
     *
     * @param Event $event event instance
     *
     * @return void
     */
    public function onDebugOutput(Event $event)
    {
        $debug = $event->getSubject();
        $connectionInfo = array();
        if ($this->connection) {
            $connectionInfo = $this->connection->getParams();
        }

        $debug->groupSummary(0);
        $groupParams = array(
            'Doctrine',
        );
        if ($connectionInfo) {
            $groupParams[] = $connectionInfo['url'];
        }
        $groupParams[] = $debug->meta(array(
            'argsAsParams' => false,
            'icon' => $this->icon,
            'level' => 'info',
        ));
        \call_user_func_array(array($debug, 'groupCollapsed'), $groupParams);
        $debug->log('logged operations: ', \count($this->loggedStatements));
        $debug->time('total time', $this->getTimeSpent());
        $debug->log('max memory usage', $debug->utilities->getBytes($this->getPeakMemoryUsage()));
        if ($connectionInfo) {
            $debug->log('connection info', $connectionInfo);
        }
        $debug->groupEnd();
        $debug->groupEnd();
    }

    /**
     * {@inheritDoc}
     */
    public function startQuery($sql, array $params = null, array $types = null)
    {
        $this->statementInfo = new StatementInfo($sql, $params, $types);
    }

    /**
     * {@inheritDoc}
     */
    public function stopQuery()
    {
        $statementInfo = $this->statementInfo;
        $statementInfo->end();
        $statementInfo->appendLog($this->debug);
        $this->loggedStatements[] = $statementInfo;
    }
}
