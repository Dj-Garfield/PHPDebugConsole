<?php

namespace bdk\Test\Debug\Collector;

use bdk\Debug\Collector\StatementInfo;
use bdk\Debug\LogEntry;
use bdk\Test\Debug\DebugTestFramework;
use Exception;

/**
 * Test Mysqli debug collector
 *
 * @covers \bdk\Debug\Collector\StatementInfo
 */
class StatementInfoTest extends DebugTestFramework
{
    public function testConstruct()
    {
        $sql = 'SELECT `first_name`, `last_name`, `password` FROM `users` u'
            . ' LEFT JOIN `user_info` ui ON ui.user_id = u.id'
            . ' WHERE u.username = ?'
            . ' LIMIT 1';
        $params = array('bkent');
        $types = array('s');
        $info = new StatementInfo($sql, $params, $types);
        $exception = new Exception('it broke', 666);
        $info->end($exception, 1);
        $info->setMemoryUsage(123);
        $this->assertIsFloat($info->duration);
        $this->assertSame(123, $info->memoryUsage);
        $this->assertSame(666, $info->errorCode);
        $this->assertSame('it broke', $info->errorMessage);
        $debugInfo = $info->__debugInfo();
        $this->assertInstanceOf('Exception', $debugInfo['exception']);
        $this->assertIsFloat($debugInfo['duration']);
        $this->assertSame(123, $debugInfo['memoryUsage']);
        $this->assertSame($params, $debugInfo['params']);
        $this->assertSame($types, $debugInfo['types']);
        $this->assertSame(1, $debugInfo['rowCount']);
        $this->assertSame($sql, $sql);
    }

    public function testAppendLog()
    {
        $sql = 'SELECT `first_name`, `last_name`, `password` FROM `users` u'
            . ' LEFT JOIN `user_info` ui ON ui.user_id = u.id'
            . ' WHERE u.username = ?'
            . ' LIMIT 1';
        $params = array('bkent');
        $types = array('s');
        $info = new StatementInfo($sql, $params, $types);
        $exception = new Exception('it broke', 666);
        $info->end($exception, 1);
        $info->setDuration(0.0123);
        $info->appendLog($this->debug);
        $logEntries = $this->getLogEntries();
        // echo \json_encode($logEntries, JSON_PRETTY_PRINT);
        $logEntriesExpectJson = <<<'EOD'
        [
            {
                "method": "groupCollapsed",
                "args": ["SELECT `first_name`, `last_name`, `password` FROM `users`\u2026"],
                "meta": {"boldLabel": false, "icon": "fa fa-list-ul"}
            },
            {
                "method": "log",
                "args": [
                    {
                        "addQuotes": false,
                        "attribs": {
                            "class": ["highlight", "language-sql"]
                        },
                        "brief": false,
                        "contentType": "application\/sql",
                        "debug": "\u0000debug\u0000",
                        "prettified": true,
                        "prettifiedTag": false,
                        "strlen": null,
                        "type": "string",
                        "typeMore": null,
                        "value": "SELECT \n  `first_name`, \n  `last_name`, \n  `password` \nFROM \n  `users` u \n  LEFT JOIN `user_info` ui ON ui.user_id = u.id \nWHERE \n  u.username = ? \nLIMIT \n  1",
                        "visualWhiteSpace": false
                    }
                ],
                "meta": {
                    "attribs": {
                        "class": ["no-indent"]
                    }
                }
            },
            {
                "method": "table",
                "args": [
                    [
                        {"value": "bkent", "type": "s"}
                    ]
                ],
                "meta": {
                    "caption": "parameters",
                    "sortable": true,
                    "tableInfo": {
                        "class": null,
                        "columns": [
                            {"key": "value"},
                            {"key": "type"}
                        ],
                        "haveObjRow": false,
                        "indexLabel": null,
                        "rows": [],
                        "summary": null
                    }
                }
            },
            {
                "method": "time",
                "args": ["duration: 12.3 ms"],
                "meta": []
            },
            {
                "method": "log",
                "args": ["memory usage", "6.13 kB"],
                "meta": []
            },
            {
                "method": "warn",
                "args": [
                    "%cLIMIT%c without %cORDER BY%c causes non-deterministic results",
                    "font-family:monospace",
                    "",
                    "font-family:monospace",
                    ""
                ],
                "meta": {
                    "detectFiles": true,
                    "file": "\/Users\/bkent\/Dropbox\/htdocs\/common\/vendor\/bdk\/PHPDebugConsole\/tests\/Debug\/Collector\/StatementInfoTest.php",
                    "line": 53,
                    "uncollapse": false
                }
            },
            {
                "method": "warn",
                "args": [
                    "Exception: it broke (code 666)"
                ],
                "meta": {
                    "detectFiles": true,
                    "file": "\/Users\/bkent\/Dropbox\/htdocs\/common\/vendor\/bdk\/PHPDebugConsole\/tests\/Debug\/Collector\/StatementInfoTest.php",
                    "line": 53,
                    "uncollapse": true
                }
            },
            {
                "method": "groupEnd",
                "args": [],
                "meta": []
            }
        ]
EOD;
        $logEntriesExpect = \json_decode($logEntriesExpectJson, true);
        // duration
        // $logEntriesExpect[3]['args'][0] = $logEntries[3]['args'][0];
        // memory usage
        $logEntriesExpect[4]['args'][1] = $logEntries[4]['args'][1];
        $logEntriesExpect[5]['meta']['file'] = $logEntries[5]['meta']['file'];
        $logEntriesExpect[5]['meta']['line'] = $logEntries[5]['meta']['line'];
        $logEntriesExpect[6]['meta']['file'] = $logEntries[6]['meta']['file'];
        $logEntriesExpect[6]['meta']['line'] = $logEntries[6]['meta']['line'];
        $this->assertSame($logEntriesExpect, $logEntries);
    }
}
