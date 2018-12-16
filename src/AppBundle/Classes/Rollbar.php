<?php
namespace AppBundle\Classes;

class Rollbar
{
    public static function shouldIgnore($isUncaught, $exception, $payload)
    {
        \AppBundle\Classes\NoOp::ignore([$isUncaught, $payload]);
        $source = $exception;
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
            mb_stripos($source->getMessage(), "Untrusted Host") !== false) {
            return true;
        }

        // Verify: GET -UsEd https://wearesosure.com/login_check
        if ($source instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException &&
            (mb_stripos($source->getMessage(), "GET /login_check") !== false ||
                mb_stripos($source->getMessage(), "OPTIONS /login_check") !== false)) {
            return true;
        }

        // Uncertain how to verify, AccessDeniedHttpExceptionVoter is supposed to prevent rollbar
        // however, messages on occasion still come through. It might occur when session times out...
        // But hopefully this will prevent
        if ($source instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
            return true;
        }

        // Rate limiting message
        // There have been too many password reset requests from this ip address recently.
        //  Please wait 10 minutes and try again
        if ($source instanceof \Symfony\Component\HttpKernel\Exception\HttpException &&
            mb_stripos($source->getMessage(), "too many password reset requests") !== false) {
            return true;
        }

        return false;
    }
}
