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
use bdk\Debug\Plugin\Prism;
use bdk\Debug\Abstraction\Abstraction;
use Curl\Curl;
use ReflectionMethod;

/**
 * Extend php-curl-class to log each request
 *
 * @see https://github.com/php-curl-class/php-curl-class
 */
class PhpCurlClass extends Curl
{
    private $debug;
    private $icon = 'fa fa-exchange';
    private $optionsDebug = array(
        'inclResponseBody' => false,
        'prettyResponseBody' => true,
        'inclInfo' => false,
        'verbose' => false,
    );
    private $optionsConstDebug = array();
    private $prismAdded = false;

    public $rawRequestHeaders = '';

    /**
     * @var array constant value to array of names
     */
    protected static $optionConstants = array();

    /**
     * Constructor
     *
     * @param array $options options
     * @param Debug $debug   (optional) Specify PHPDebugConsole instance
     *                        if not passed, will create Curl channnel on singleton instance
     *                        if root channel is specified, will create a Curl channel
     */
    public function __construct($options = array(), Debug $debug = null)
    {
        $this->optionsDebug = \array_merge($this->optionsDebug, $options);
        if (!$debug) {
            $debug = Debug::_getChannel('Curl', array('channelIcon' => $this->icon));
        } elseif ($debug === $debug->rootInstance) {
            $debug = $debug->getChannel('Curl', array('channelIcon' => $this->icon));
        }
        $this->debug = $debug;
        $this->buildConstLookup();
        parent::__construct();
        if ($options['verbose']) {
            $this->verbose(true, \fopen('php://temp', 'rw'));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function close()
    {
        parent::close();
        $this->optionsConstDebug = array();
    }

    /**
     * {@inheritDoc}
     */
    public function exec($ch = null)
    {
        $options = $this->buildDebugOptions();
        $this->debug->groupCollapsed(
            'Curl',
            $this->getHttpMethod($options),
            $options['CURLOPT_URL'],
            $this->debug->meta('icon', $this->icon)
        );
        $this->debug->log('options', $options);
        $return = parent::exec($ch);
        $verboseOutput = null;
        if (!empty($options['CURLOPT_VERBOSE'])) {
            /*
                CURLINFO_HEADER_OUT doesn't work with verbose...
                but we can get the request headers from the verbose output
            */
            $pointer = $options['CURLOPT_STDERR'];
            \rewind($pointer);
            $verboseOutput = \stream_get_contents($pointer);
            \preg_match_all('/> (.*?)\r\n\r\n/s', $verboseOutput, $matches);
            $this->rawRequestHeaders = \end($matches[1]);
            $reflectionMethod = new ReflectionMethod($this, 'parseRequestHeaders');
            $reflectionMethod->setAccessible(true);
            $this->requestHeaders = $reflectionMethod->invoke($this, $this->rawRequestHeaders);
        } else {
            $this->rawRequestHeaders = $this->getInfo(CURLINFO_HEADER_OUT);
        }
        if ($this->error) {
            $this->debug->warn($this->errorCode, $this->errorMessage);
        }
        if ($this->effectiveUrl !== $options['CURLOPT_URL']) {
            \preg_match_all('/^(Location:|URI: )(.*?)\r\n/im', $this->rawResponseHeaders, $matches);
            $this->debug->log('Redirect(s)', $matches[2]);
        }
        $this->debug->log('request headers', $this->rawRequestHeaders);
        // Curl provides no means to get the request body
        $this->debug->log('response headers', $this->rawResponseHeaders);
        if ($this->optionsDebug['inclResponseBody']) {
            $body = $this->getResponseBody();
            $this->debug->log(
                'response body %c%s',
                'font-style: italic; opacity: 0.8;',
                $body instanceof Abstraction
                    ? '(prettified)'
                    : '',
                $body
            );
        }
        if ($this->optionsDebug['inclInfo']) {
            $this->debug->log('info', $this->getInfo());
        }
        if ($verboseOutput) {
            $this->debug->log('verbose', $verboseOutput);
        }
        $this->debug->groupEnd();
        return $return;
    }

    /**
     * Get the http method used (GET, POST, etc)
     *
     * @param array $options our human readable curl options
     *
     * @return string
     */
    private function getHttpMethod($options)
    {
        $method = 'GET';
        if (isset($options['CURLOPT_CUSTOMREQUEST'])) {
            $method = $options['CURLOPT_CUSTOMREQUEST'];
        } elseif (!empty($options['CURLOPT_POST'])) {
            $method = 'POST';
        }
        return $method;
    }

    /**
     * {@inheritDoc}
     */
    public function setOpt($option, $value)
    {
        $return = parent::setOpt($option, $value);
        if ($return) {
            $this->optionsConstDebug[$option] = $value;
        }
        return $return;
    }

    /**
     * Set self::optionConstants  CURLOPT_* value => names array
     *
     * @return void
     */
    private function buildConstLookup()
    {
        if (self::$optionConstants) {
            return;
        }
        $consts = \get_defined_constants(true)['curl'];
        \ksort($consts);
        $valToNames = array();
        foreach ($consts as $name => $val) {
            if (\strpos($name, 'CURLOPT') !== 0 && $name !== 'CURLINFO_HEADER_OUT') {
                continue;
            }
            if (!isset($valToNames[$val])) {
                $valToNames[$val] = array();
            }
            $valToNames[$val][] = $name;
        }
        \ksort($valToNames);
        self::$optionConstants = $valToNames;
    }

    /**
     *  Build an array of human-readable options used
     *
     * @return array
     */
    private function buildDebugOptions()
    {
        $opts = array();
        foreach ($this->optionsConstDebug as $constVal => $val) {
            $name = \implode(' / ', self::$optionConstants[$constVal]);
            $opts[$name] = $val;
        }
        if (isset($opts['CURLOPT_POSTFIELDS']) && \is_string($opts['CURLOPT_POSTFIELDS'])) {
            \parse_str($opts['CURLOPT_POSTFIELDS'], $opts['CURLOPT_POSTFIELDS']);
        }
        \ksort($opts);
        return $opts;
    }

    /**
     * Get the response body
     *
     * Will return formatted Abstraction if html/json/xml
     *
     * @return Abstraction|string|null
     */
    private function getResponseBody()
    {
        // $bodySize = $msg->getBody()->getSize();
        $body = $this->rawResponse;
        if (\strlen($body) === 0) {
            return null;
        }
        $contentType = $this->responseHeaders['content-type'];
        $prettify = $this->optionsDebug['prettyResponseBody'];
        if ($prettify && \preg_match('#\b(html|json|xml)\b#', $contentType, $matches)) {
            $lang = $type = $matches[1];
            if ($type === 'html') {
                $lang = 'markup';
            } elseif ($type === 'json') {
                $body = $this->debug->utilities->prettyJson($body);
            } elseif ($type === 'xml') {
                $body = $this->debug->utilities->prettyXml($body);
            }
            if (!$this->prismAdded) {
                $this->debug->addPlugin(new Prism());
                $this->prismAdded = true;
            }
            return new Abstraction(array(
                'type' => 'string',
                'attribs' => array(
                    'class' => 'language-'.$lang.' prism',
                ),
                'addQuotes' => false,
                'visualWhiteSpace' => false,
                'value' => $body,
            ));
        } else {
            return $body;
        }
    }
}
