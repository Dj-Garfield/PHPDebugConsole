<?php

/**
 * This file is part of PHPDebugConsole
 *
 * @package   PHPDebugConsole
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2021 Brad Kent
 * @version   v3.0
 */

namespace bdk\Debug\Psr7lite;

use bdk\Debug\Psr7lite\Stream;

/**
 * INTERNAL USE ONLY
 *
 * @psalm-consistent-constructor
 */
class ServerRequest extends ServerRequestBase
{

    /** @var array */
    private $attributes = array();

    /**
     * @var array $_COOKIE
     */
    private $cookie = array();

    /** @var array */
    private $files = array();

    /**
     * @var array $_GET
     */
    private $get = array();

    /**
     * @var null|array|object $_POST
     */
    private $post = null;

    /**
     * @var array $_SERVER
     */
    private $server = array();

    /**
     * Constructor
     *
     * @param string                                    $method       The HTTP method associated with the request.
     * @param \Psr\Http\Message\UriInterface|Uri|string $uri          The URI associated with the request.
     * @param array                                     $serverParams An array of Server API (SAPI) parameters with
     *     which to seed the generated request instance. (and headers)
     */
    public function __construct($method = 'GET', $uri = '', $serverParams = array())
    {
        parent::__construct($method, $uri);
        $headers = $this->getHeadersViaServer($serverParams);
        $query = $this->getUri()->getQuery();
        $this->get = $query !== ''
            ? self::parseStr($query)
            : array();
        $this->server = $serverParams;
        $this->protocolVersion = isset($serverParams['SERVER_PROTOCOL'])
            ? \str_replace('HTTP/', '', $serverParams['SERVER_PROTOCOL'])
            : '1.1';
        $this->setHeaders($headers);
    }

    /**
     * Instantiate self from superglobals
     *
     * @return static
     *
     * @SuppressWarnings(PHPMD.Superglobals)
     */
    public static function fromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? $_SERVER['REQUEST_METHOD']
            : 'GET';
        $uri = self::uriFromGlobals();
        $files = self::filesFromGlobals($_FILES);
        $serverRequest = new static($method, $uri, $_SERVER);
        $contentType = $serverRequest->getHeaderLine('Content-Type');
        $parsedBody = $method !== 'GET'
            ? self::postFromInput($contentType)
            : null;
        $query = $uri->getQuery();
        $queryParams = $query !== ''
            ? self::parseStr($query)
            : $_GET;
        return $serverRequest
            ->withBody(new Stream(\fopen('php://input', 'r+')))
            ->withCookieParams($_COOKIE)
            ->withParsedBody($parsedBody)
            ->withQueryParams($queryParams)
            ->withUploadedFiles($files);
    }

    /**
     * Get $_SERVER values
     *
     * @return array
     */
    public function getServerParams()
    {
        return $this->server;
    }

    /**
     * Get Cookie values
     *
     * @return array
     */
    public function getCookieParams()
    {
        return $this->cookie;
    }

    /**
     * @param array $cookies $_COOKIE
     *
     * @return static
     */
    public function withCookieParams($cookies)
    {
        $new = clone $this;
        $new->cookie = $cookies;
        return $new;
    }

    /**
     * Get $_GET data
     *
     * @return array
     */
    public function getQueryParams()
    {
        return $this->get;
    }

    /**
     * @param array $get $_GET
     *
     * @return static
     */
    public function withQueryParams($get)
    {
        $new = clone $this;
        $new->get = $get;
        return $new;
    }

    /**
     * Retrieve normalized file upload data.
     *
     * This method returns upload metadata in a normalized tree, with each leaf
     * an instance of Psr\Http\Message\UploadedFileInterface.
     *
     * @return array An array tree of UploadedFileInterface instances (or an empty array)
     */
    public function getUploadedFiles()
    {
        return $this->files;
    }

    /**
     * Create a new instance with the specified uploaded files.
     *
     * @param array $uploadedFiles An array tree of UploadedFileInterface instances.
     *
     * @return static
     * @throws InvalidArgumentException if an invalid structure is provided.
     */
    public function withUploadedFiles($uploadedFiles)
    {
        $this->assertUploadedFiles($uploadedFiles);
        $new = clone $this;
        $new->files = $uploadedFiles;
        return $new;
    }

    /**
     * Get $_POST data
     *
     * @return null|array|object
     */
    public function getParsedBody()
    {
        return $this->post;
    }

    /**
     * @param null|array|object $post The deserialized body data ($_POST).
     *                                  This will typically be in an array or object
     *
     * @return static
     */
    public function withParsedBody($post)
    {
        $this->assertParsedBody($post);
        $new = clone $this;
        $new->post = $post;
        return $new;
    }

    /**
     * Retrieve attributes derived from the request.
     *
     * The request "attributes" may be used to allow injection of any
     * parameters derived from the request: e.g., the results of path
     * match operations; the results of decrypting cookies; the results of
     * deserializing non-form-encoded message bodies; etc. Attributes
     * will be application and request specific, and CAN be mutable.
     *
     * @return mixed[] Attributes derived from the request.
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Retrieve a single derived request attribute.
     *
     * @param string $name    The attribute name.
     * @param mixed  $default Default value to return if the attribute does not exist.
     *
     * @return mixed
     */
    public function getAttribute($name, $default = null)
    {
        if (\array_key_exists($name, $this->attributes) === false) {
            return $default;
        }
        return $this->attributes[$name];
    }

    /**
     * Return an instance that removes the specified derived request attribute.
     *
     * @param string $name  attribute name
     * @param mixed  $value value
     *
     * @return static
     */
    public function withAttribute($name, $value)
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    /**
     * Return an instance that removes the specified derived request attribute.
     *
     * @param string $name attribute name
     *
     * @return static
     */
    public function withoutAttribute($name)
    {
        if (\array_key_exists($name, $this->attributes) === false) {
            return $this;
        }
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }
}
