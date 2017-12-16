<?php
namespace AppBundle\Classes;

class Rollbar extends \RollbarNotifier
{
    public function __construct($config)
    {
        parent::__construct($config);
        $this->checkIgnore = (function ($isUncaught, $exception, $payload) {
            return $this->shouldIgnore($isUncaught, $exception, $payload);
        });
    }

    public function shouldIgnore($isUncaught, $exception, $payload) {
        if ($exception instanceof \RollbarException) {
            $source = $exception->getException();
            // Don't sent 404's
            // Verify: GET -UsEd https://wearesosure.com/not-found
            if ($source instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                $source instanceof \Symfony\Component\Routing\Exception\ResourceNotFoundException) {
                return true;
            }

            // Don't sent Untrusted Host
            // Verify: GET -UsEd -H "HOST: 52.19.177.49" https://wearesosure.com
            if (($source instanceof \UnexpectedValueException ||
                $source instanceof \Symfony\Component\HttpKernel\Exception\BadRequestHttpException) &&
                stripos($source->getMessage(), "Untrusted Host") !== false) {
                return true;
            }

            // Verify: GET -UsEd https://wearesosure.com/login_check
            if ($source instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException &&
                stripos($source->getMessage(), "GET /login_check") !== false) {
                return true;
            }

            // Uncertain how to verify, AccessDeniedHttpExceptionVoter is supposed to prevent rollbar
            // however, messages on occasion still come through. It might occur when session times out...
            // But hopefully this will prevent
            if ($source instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
                return true;
            }
        }

        return false;
    }
}
