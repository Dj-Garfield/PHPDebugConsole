<?php

namespace bdk\Test\Debug\Collector;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\LogEntry;
use bdk\PubSub\Event;
use bdk\Test\Debug\DebugTestFramework;

/**
 * PHPUnit tests for Debug class
 */
class PdoTest extends DebugTestFramework
{
    private static $client;

    public static function setUpBeforeClass(): void
    {
        $createTableSql = <<<'EOD'
        CREATE TABLE IF NOT EXISTS bob (
                k VARCHAR(255) NOT NULL PRIMARY KEY,
                v BLOB,
                t char(32) NOT NULL,
                e datetime NULL DEFAULT NULL,
                ct INT NULL DEFAULT 0,
                KEY e
            )
EOD;

        $pdoBase = new \PDO('sqlite::memory:');
        self::$client = new \bdk\Debug\Collector\Pdo($pdoBase);
        self::$client->exec($createTableSql);
    }

    public function testExecute()
    {
        $statement = self::$client->prepare('SELECT *
            FROM `bob`
            WHERE e < :datetime');
        $datetime = '2020-12-04 22:00:00';
        $line = __LINE__ + 2;
        $statement->bindParam(':datetime', $datetime, \PDO::PARAM_STR);
        $statement->execute();

        $logEntries = $this->getLogEntries(8);

        $logEntriesExpect = array(
            array(
                'method' => 'groupCollapsed',
                'args' => array(
                    'SELECT * FROM `bob`…',
                ),
                'meta' => array(
                    'boldLabel' => false,
                    'channel' => 'general.PDO',
                    'icon' => 'fa fa-database',
                ),
            ),
            array(
                'method' => 'log',
                'args' => array(
                    array(
                        'addQuotes' => false,
                        'attribs' => array(
                            'class' => array('highlight', 'language-sql'),
                        ),
                        'contentType' => 'application/sql',
                        'debug' => Abstracter::ABSTRACTION,
                        'prettified' => true,
                        'prettifiedTag' => false,
                        'strlen' => null,
                        'type' => Abstracter::TYPE_STRING,
                        'typeMore' => null,
                        'value' => "SELECT \n  * \nFROM \n  `bob` \nWHERE \n  e < :datetime",
                        'visualWhiteSpace' => false,
                    ),
                ),
                'meta' => array(
                    'attribs' => array(
                        'class' => array('no-indent'),
                    ),
                    'channel' => 'general.PDO',
                ),
            ),
            array(
                'method' => 'table',
                'args' => array(
                    array(
                        ':datetime' => array(
                            'value' => $datetime,
                            'type' => array(
                                'debug' => Abstracter::ABSTRACTION,
                                'name' => 'PDO::PARAM_STR',
                                'type' => Abstracter::TYPE_CONST,
                                'value' => 2,
                            ),
                        ),
                    ),
                ),
                'meta' => array(
                    'caption' => 'parameters',
                    'channel' => 'general.PDO',
                    'sortable' => true,
                    'tableInfo' => array(
                        'class' => null,
                        'columns' => array(
                            array('key' => 'value'),
                            array('key' => 'type'),
                        ),
                        'haveObjRow' => false,
                        'indexLabel' => null,
                        'rows' => array(),
                        'summary' => null,
                    ),
                ),
            ),
            array(
                'asString' => true,
                'method' => 'time',
                'args' => array(
                    'duration: %f %ss',
                ),
                'meta' => array(
                    'channel' => 'general.PDO',
                ),
            ),
            array(
                'asString' => true,
                'method' => 'log',
                'args' => array(
                    'memory usage',
                    '%d B',
                ),
                'meta' => array(
                    'channel' => 'general.PDO',
                ),
            ),
            array(
                'method' => 'warn',
                'args' => array(
                    'Use %cSELECT *%c only if you need all columns from table',
                    'font-family:monospace',
                    '',
                ),
                'meta' => array(
                    'channel' => 'general.PDO',
                    'detectFiles' => true,
                    'file' => __FILE__,
                    'line' => $line,
                    'uncollapse' => false,
                ),
            ),
            array(
                'method' => 'log',
                'args' => array(
                    'rowCount',
                    0,
                ),
                'meta' => array(
                    'channel' => 'general.PDO',
                )
            ),
            array(
                'method' => 'groupEnd',
                'args' => array(),
                'meta' => array(
                    'channel' => 'general.PDO',
                ),
            ),
        );
        foreach ($logEntriesExpect as $i => $valsExpect) {
            $asString = !empty($valsExpect['asString']);
            unset($valsExpect['asString']);
            if ($asString) {
                $this->assertStringMatchesFormat(\json_encode($valsExpect), \json_encode($logEntries[$i]));
                continue;
            }
            $this->assertSame($valsExpect, $logEntries[$i]);
        }
        $this->assertIsString(self::$client->lastInsertId());
    }

    public function testExec()
    {
        $count = self::$client->exec('DELETE FROM `bob`');
        $this->assertIsInt($count);

        $logEntriesExpectJson = <<<'EOD'
        [
            {
                "method": "groupCollapsed",
                "args": ["DELETE FROM `bob`"],
                "meta": {"boldLabel": false, "channel": "general.PDO", "icon": "fa fa-database"}
            },
            {
                "method": "time",
                "args": ["duration: 46.0148 \u03bcs"],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "log",
                "args": ["memory usage", "0 B"],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "groupEnd",
                "args": [],
                "meta": {"channel": "general.PDO"}
            }
        ]
EOD;
        $logEntriesExpect = \json_decode($logEntriesExpectJson, true);

        $logEntries = $this->getLogEntries();


        // duration
        $logEntriesExpect[1]['args'][0] = $logEntries[1]['args'][0];
        // memory
        $logEntriesExpect[2]['args'][1] = $logEntries[2]['args'][1];

        $this->assertSame($logEntriesExpect, $logEntries);
    }

    public function testTransaction()
    {
        self::$client->beginTransaction();
        self::$client->inTransaction();
        self::$client->commit();
        $logEntriesExpectJson = <<<'EOD'
        [
            {
                "method": "group",
                "args": ["transaction"],
                "meta": {"channel": "general.PDO", "icon": "fa fa-database"}
            },
            {
                "method": "groupEndValue",
                "args": ["return", true ],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "groupEnd",
                "args": [],
                "meta": {"channel": "general.PDO"}
            }
        ]
EOD;
        $this->assertSame(
            \json_decode($logEntriesExpectJson, true),
            $this->getLogEntries()
        );

        self::$client->beginTransaction();
        self::$client->rollback();
        $logEntriesExpectJson = <<<'EOD'
        [
            {
                "method": "group",
                "args": ["transaction"],
                "meta": {"channel": "general.PDO", "icon": "fa fa-database"}
            },
            {
                "method": "groupEndValue",
                "args": ["return", "rolled back"],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "groupEnd",
                "args": [],
                "meta": {"channel": "general.PDO"}
            }
        ]
EOD;
        $this->assertSame(
            \json_decode($logEntriesExpectJson, true),
            $this->getLogEntries(3)
        );
    }


    public function testDebugOutput()
    {
        self::$client->onDebugOutput(new Event($this->debug));
        $logEntriesExpectJson = <<<'EOD'
        [
            {
                "method": "groupCollapsed",
                "args": ["PDO info", "sqlite"],
                "meta": {"argsAsParams": false, "channel": "general.PDO", "icon": "fa fa-database", "level": "info"}
            },
            {
                "method": "log",
                "args": ["logged operations: ", 3],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "time",
                "args": ["total time: 2.28 ms"],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "log",
                "args": ["max memory usage", "280.73 kB"],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "log",
                "args": [
                    "server info",
                    {"Version": "3.36.0"}
                ],
                "meta": {"channel": "general.PDO"}
            },
            {
                "method": "groupEnd",
                "args": [],
                "meta": {"channel": "general.PDO"}
            }
        ]
EOD;
        $logEntriesExpect = \json_decode($logEntriesExpectJson, true);

        $logEntries = $this->getLogEntries(null, 'logSummary/0');
        // duration
        $logEntriesExpect[2]['args'][0] = $logEntries[2]['args'][0];
        // memory
        $logEntriesExpect[3]['args'][1] = $logEntries[3]['args'][1];
        // server info
        $logEntriesExpect[4]['args'][1] = $logEntries[4]['args'][1];
        $this->assertSame($logEntriesExpect, $logEntries);
    }

    public function testMisc()
    {
        $this->assertIsString(self::$client->errorCode());
        $this->assertIsArray(self::$client->errorInfo());
        $this->assertSame("'1'' OR ''1''=''1'' --'", self::$client->quote("1' OR '1'='1' --"));
    }

    protected function getLogEntries($count = null, $where = 'log')
    {
        $logEntries = $this->debug->data->get($where);
        if (\in_array($where, array('log','alerts')) || \preg_match('#^logSummary[\./]\d+$#', $where)) {
            if ($count) {
                $logEntries = \array_slice($logEntries, 0 - $count);
            }
            return \array_map(function (LogEntry $logEntry) {
                return $this->logEntryToArray($logEntry);
            }, $logEntries);
        } elseif ($where === 'logSummary') {
            foreach ($logEntries as $priority => $entries) {
                $logEntries[$priority] = \array_map(function (LogEntry $logEntry) {
                    return $this->logEntryToArray($logEntry);
                }, $entries);
            }
            return $logEntries;
        }
    }
}
