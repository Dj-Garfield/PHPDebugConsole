<?php

namespace bdk\Test\Debug\Plugin;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Plugin\LogReqRes;
// use bdk\Debug\Plugin\Redaction;
use bdk\HttpMessage\Response;
use bdk\HttpMessage\ServerRequest;
use bdk\HttpMessage\Stream;
use bdk\HttpMessage\UploadedFile;
use bdk\Test\Debug\DebugTestFramework;

/**
 * PHPUnit tests for Debug class
 *
 * @covers \bdk\Debug\Plugin\LogReqRes
 */
class LogReqResTest extends DebugTestFramework
{
    public function testLogRes()
    {
        $this->debug->pluginLogReqRes->logResponse();
        $logEntries = $this->helper->deObjectifyData($this->debug->data->get('log'));
        $this->assertSame(array(), $logEntries);

        $this->debug->data->set('log', array());
        $json = \json_encode(array('foo' => 'bar'));
        $response = (new Response())
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new Stream($json));
        $this->debug->setCfg(array(
            'logResponse' => 'auto',
            'serviceProvider' => array(
                'response' => $response
            ),
        ));
        $this->debug->pluginLogReqRes->logResponse();
        $logEntries = $this->helper->deObjectifyData($this->debug->data->get('log'));
        $this->assertSame('Response', $logEntries[0]['args'][0]);
        $this->assertSame('Request / Response', $logEntries[0]['meta']['channel']);
        $this->assertSame('table', $logEntries[1]['method']);
        $this->assertSame(array(
            'Content-Type' => array('value' => 'application/json'),
        ), $logEntries[1]['args'][0]);
        $this->assertSame('response headers', $logEntries[1]['meta']['caption']);
        $this->assertSame('{' . "\n"
            . '    "foo": "bar"' . "\n"
            . '}', $logEntries[2]['args'][4]['value']);
        $this->assertSame(array(
            'foo' => 'bar',
        ), $logEntries[2]['args'][4]['valueDecoded']);

        $this->debug->data->set('log', array());
        $html = '<!DOCTYPE html><html><head><title>WebCo WebPage</title></head><body>Clever Response</body></html>';
        $response = (new Response())
            ->withHeader('Content-Type', 'text/html')
            ->withBody(new Stream($html));
        $this->debug->setCfg(array(
            // 'logEnvInfo' => true,
            'logResponse' => 'auto',
            'serviceProvider' => array(
                'response' => $response
            ),
        ));
        $this->debug->pluginLogReqRes->logResponse();
        $logEntries = $this->helper->deObjectifyData($this->debug->data->get('log'));
        $this->assertSame('Not logging response body for Content-Type "text/html"', \end($logEntries)['args'][0]);

        $this->debug->obEnd();
    }

    public function testLogPost()
    {
        $logReqRes = new LogReqRes();
        $this->debug->addPlugin($logReqRes);
        $this->debug->data->set('log', array());
        $this->debug->data->set('logSummary', array());
        $this->debug->setCfg('logRequestInfo', true);

        $reflect = new \ReflectionObject($logReqRes);
        $logPostMeth = $reflect->getMethod('logPost');
        $logPostMeth->setAccessible(true);
        $logRequestMeth = $reflect->getMethod('logRequest');
        $logRequestMeth->setAccessible(true);

        // customMethodReqRes caches serverParams..  use serverParamsRef to clear it
        /*
        $debugRef = new \ReflectionObject($this->debug);
        $internalProp = $debugRef->getProperty('internal');
        $internalProp->setAccessible(true);
        $internal = $internalProp->getValue($this->debug);
        $internalRef = new \ReflectionObject($internal);
        $serverParamsRef = $internalRef->getProperty('serverParams');
        $serverParamsRef->setAccessible(true);
        */
        $reqResRef = new \ReflectionObject($this->debug->customMethodReqRes);
        $serverParamsRef = $reqResRef->getProperty('serverParams');
        $serverParamsRef->setAccessible(true);

        /*
            valid form post
        */
        $post = array('foo' => 'bar');
        $this->debug->setCfg('serviceProvider', array(
            'request' => $this->debug->request
                ->withMethod('POST')
                ->withParsedBody($post)
                ->withBody(new Stream(\http_build_query($post))),
        ));
        $logPostMeth->invoke($logReqRes);
        $this->assertSame(
            array(
                'method' => 'log',
                'args' => array('$_POST', $post),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'redact' => true,
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/0'))
        );
        $this->debug->data->set('log', array());

        /*
            json properly posted
        */
        $requestBody = \json_encode(array('foo' => 'bar=baz'));
        $this->debug->setCfg('serviceProvider', array(
            'request' => $this->debug->request
                ->withMethod('POST')
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new Stream($requestBody))
                ->withParsedBody(array()),
        ));
        $logPostMeth->invoke($logReqRes);
        $this->assertEquals(
            array(
                'method' => 'log',
                'args' => array(
                    'php://input',
                    // 'font-style: italic; opacity: 0.8;',
                    // '(prettified)',
                    array(
                        'addQuotes' => false,
                        'attribs' => array(
                            'class' => array('highlight', 'language-json'),
                        ),
                        'contentType' => 'application/json',
                        'debug' => Abstracter::ABSTRACTION,
                        'prettified' => true,
                        'prettifiedTag' => true,
                        'strlen' => null,
                        'type' => Abstracter::TYPE_STRING,
                        'typeMore' => Abstracter::TYPE_STRING_JSON,
                        'value' => \json_encode(\json_decode($requestBody), JSON_PRETTY_PRINT),
                        'valueDecoded' => \json_decode($requestBody, true),
                        'visualWhiteSpace' => false,
                    ),
                ),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'redact' => true,
                )
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/0'))
        );
        $this->debug->data->set('log', array());

        /*
            json improperly posted
        */
        $requestBody = \json_encode(array('foo' => 'bar=baz'));
        \parse_str($requestBody, $parsedBody);
        $this->debug->setCfg('serviceProvider', array(
            'request' => $this->debug->request
                ->withMethod('POST')
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody(new Stream($requestBody))
                ->withParsedBody($parsedBody),
        ));
        $logPostMeth->invoke($logReqRes);
        $this->assertSame(
            array(
                'method' => 'warn',
                'args' => array('It appears application/json was posted with the wrong Content-Type' . "\n"
                    . 'Pay no attention to $_POST and instead use php://input'),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'detectFiles' => false,
                    'file' => null,
                    'line' => null,
                    'uncollapse' => true,
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/0'))
        );
        $this->assertEquals(
            array(
                'method' => 'log',
                'args' => array(
                    'php://input',
                    // 'font-style: italic; opacity: 0.8;',
                    // '(prettified)',
                    array(
                        'addQuotes' => false,
                        'attribs' => array(
                            'class' => array('highlight', 'language-json'),
                        ),
                        'contentType' => 'application/json',
                        'debug' => Abstracter::ABSTRACTION,
                        'prettified' => true,
                        'prettifiedTag' => true,
                        'strlen' => null,
                        'type' => Abstracter::TYPE_STRING,
                        'typeMore' => Abstracter::TYPE_STRING_JSON,
                        'value' => \json_encode(\json_decode($requestBody), JSON_PRETTY_PRINT),
                        'valueDecoded' => \json_decode($requestBody, true),
                        'visualWhiteSpace' => false,
                    ),
                ),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'redact' => true,
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/1'))
        );
        $this->debug->data->set('log', array());

        /*
            Post with just uploadedFiles
        */
        $this->debug->data->set('log', array());
        $serverParamsRef->setValue($this->debug->customMethodReqRes, array());
        $files = array(
            'foo' => new UploadedFile(
                TEST_DIR . '/assets/logo.png',
                10000,
                UPLOAD_ERR_OK,
                'logo.png',
                'image/png'
            ),
        );
        $this->debug->rootInstance->setCfg('serviceProvider', array(
            'request' => function () use ($files) {
                $request = new ServerRequest('POST', null, array(
                    'REQUEST_METHOD' => 'POST',
                ));
                return $request->withUploadedFiles($files);
            },
        ));
        // $this->clearServerParamCache();
        $logRequestMeth->invoke($logReqRes);
        $this->assertSame(
            array(
                'method' => 'log',
                'args' => array('$_FILES', array(
                    'foo' => array(
                        'error' => UPLOAD_ERR_OK,
                        'name' => 'logo.png',
                        'size' => \filesize(TEST_DIR . '/assets/logo.png'),
                        'tmp_name' => TEST_DIR . '/assets/logo.png',
                        'type' => 'image/png',
                    ),
                )),
                'meta' => array(
                    'channel' => 'Request / Response',
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/1'))
        );
        $this->debug->data->set('log', array());

        /*
            Post with no body
        */
        $this->debug->setCfg('serviceProvider', array(
            'request' => function () {
                return new ServerRequest('POST');
            },
        ));
        $logPostMeth->invoke($logReqRes);
        $this->assertSame(
            array(
                'method' => 'warn',
                'args' => array('POST request with no body'),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'detectFiles' => false,
                    'file' => null,
                    'line' => null,
                    'uncollapse' => true,
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/0'))
        );
        $this->debug->data->set('log', array());

        /*
            Put method
        */
        $requestBody = \json_encode(array('foo' => 'bar=bazy'));
        $this->debug->setCfg('serviceProvider', array(
            'request' => function () use ($requestBody) {
                $request = new ServerRequest('PUT');
                return $request
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody(new Stream($requestBody));
            },
        ));
        $logPostMeth->invoke($logReqRes);
        $this->assertEquals(
            array(
                'method' => 'log',
                'args' => array(
                    'php://input',
                    // 'font-style: italic; opacity: 0.8;',
                    // '(prettified)',
                    array(
                        'addQuotes' => false,
                        'attribs' => array(
                            'class' => array('highlight', 'language-json'),
                        ),
                        'contentType' => 'application/json',
                        'debug' => Abstracter::ABSTRACTION,
                        'prettified' => true,
                        'prettifiedTag' => true,
                        'strlen' => null,
                        'type' => Abstracter::TYPE_STRING,
                        'typeMore' => Abstracter::TYPE_STRING_JSON,
                        'value' => \json_encode(\json_decode($requestBody), JSON_PRETTY_PRINT),
                        'valueDecoded' => \json_decode($requestBody, true),
                        'visualWhiteSpace' => false,
                    ),
                ),
                'meta' => array(
                    'channel' => 'Request / Response',
                    'redact' => true,
                ),
            ),
            $this->helper->logEntryToArray($this->debug->data->get('log/0'))
        );
        $this->debug->data->set('log', array());

        /*
            Reset request
        */
        $serverParamsRef->setValue($this->debug->customMethodReqRes, array());
        $this->debug->setCfg('serviceProvider', array(
            'request' => function () {
                return ServerRequest::fromGlobals();
            },
        ));
    }
}