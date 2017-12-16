<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\Rollbar;

/**
 * @group unit
 */
class RollbarTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testShouldIgnore()
    {
        $rollbar = new Rollbar(null);
        $e = new \RollbarException('', new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException());
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
        $e = new \RollbarException('', new \Symfony\Component\Routing\Exception\ResourceNotFoundException());
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
        $e = new \RollbarException('', new \UnexpectedValueException('Untrusted Host'));
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
        $e = new \RollbarException('', new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException([], 'GET /login_check'));
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
        $e = new \RollbarException('', new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException());
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));

        $e = new \RollbarException('', new \Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException('Untrusted Host'));
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
        $e = new \RollbarException('', new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Untrusted Host'));
        $this->assertTrue($rollbar->shouldIgnore(false, $e, null));
    }
}
