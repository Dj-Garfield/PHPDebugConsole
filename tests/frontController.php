<?php

/**
 * php -S 127.0.0.1:8080 -t docroot frontController.php > phpd.log 2>&1 &
 */

require __DIR__ . '/../vendor/autoload.php';

$serverRequest = \bdk\HttpMessage\ServerRequest::fromGlobals();
$serverParams = $serverRequest->getServerParams();
$requestUri = $serverRequest->getUri();

$debug = \bdk\Debug::getInstance(array(
    'collect' => true,
    'output' => true,
    'route' => 'wamp',
));

\chdir($serverParams['DOCUMENT_ROOT']);

\header_remove('X-Powered-By');

$path = \ltrim($requestUri->getPath(), '/');
$realpath = \realpath($path);

if ($realpath && \is_dir($realpath)) {
    foreach (['index.php', 'index.html'] as $file) {
        $filepath = \realpath($realpath . DIRECTORY_SEPARATOR . $file);
        if ($filepath) {
            $realpath = $filepath;
            break;
        }
    }
}
if ($realpath && \is_file($realpath)) {
    if (
        \substr(\basename($realpath), 0, 1) === '.'
        || $realpath === __FILE__
    ) {
        // disallowed file
        \header('HTTP/1.1 404 Not Found');
        echo '404 Not Found';
        return;
    }
    if (\strtolower(\substr($realpath, -4)) === '.php') {
        include $realpath;
        return;
    }
    // asset file; serve from filesystem
    return false;
}

/*
    Path was not found in webroot
*/

$extensions = array(
    'php' => 'text/html; charset=UTF-8',
    'html' => 'text/html; charset=UTF-8',
    'json' => 'application/json',
    'xml' => 'application/xml',
);
foreach ($extensions as $ext => $contentType) {
    $realpath = \realpath($path . '.' . $ext);
    if ($realpath === false) {
        continue;
    }
    \header('Content-Type:' . $contentType);
    include $realpath;
    return;
}

\header('HTTP/1.1 404 Not Found');
echo '404 Not Found';
