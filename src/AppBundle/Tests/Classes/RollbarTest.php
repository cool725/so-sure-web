<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\Rollbar;

/**
 * @group unit
 */
class RollbarTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testShouldIgnore()
    {
        $e = new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\Routing\Exception\ResourceNotFoundException();
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \UnexpectedValueException('Untrusted Host');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException([], 'GET /login_check');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(
            [],
            'OPTIONS /login_check'
        );
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException('Untrusted Host');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Untrusted Host');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \Symfony\Component\HttpKernel\Exception\HttpException(
            500,
            'There have been too many password reset requests from this ip address recently.'
        );
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \RuntimeException('No resource owner');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));

        $e = new \InvalidArgumentException('Invalid csrf token');
        $this->assertTrue(Rollbar::shouldIgnore(false, $e, null));
    }
}
