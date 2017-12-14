<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @var Composer\Autoload\ClassLoader
 */
$loader = require __DIR__.'/../app/autoload.php';
include_once __DIR__.'/../app/bootstrap.php.cache';

// Enable APC for autoloading to improve performance.
// You should change the ApcClassLoader first argument to a unique prefix
// in order to prevent cache key conflicts with other applications
// also using APC.
/*
$apcLoader = new Symfony\Component\ClassLoader\ApcClassLoader(sha1(__FILE__), $loader);
$loader->unregister();
$apcLoader->register(true);
*/

//require_once __DIR__.'/../app/AppCache.php';

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
//$kernel = new AppCache($kernel);

// When using the HttpCache, you need to call the method in your front controller instead of relying on the configuration parameter
//Request::enableHttpMethodParameterOverride();
$request = Request::createFromGlobals();
// http://symfony.com/doc/current/cookbook/request/load_balancer_reverse_proxy.html
Request::setTrustedProxies(array('127.0.0.1', $request->server->get('REMOTE_ADDR')));

// If we're getting untrusted host, user is hitting elb ip.  Difficult to find correct rewrite rules that don't also catch the elb ip probes
// so just redirect on the untrusted host exception.
try {
    $response = $kernel->handle($request);
} catch (BadRequestHttpException $e) {
    if (stripos($e->getMessage(), "Untrusted Host") !== false) {
        $response = new RedirectResponse('https://wearesosure.com');
    } else {
        throw $e;
    }
}
$response->send();
$kernel->terminate($request, $response);
