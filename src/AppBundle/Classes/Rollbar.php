<?php
namespace AppBundle\Classes;

class Rollbar extends \RollbarNotifier
{
    public function __construct($config)
    {
        parent::__construct($config);
        $this->checkIgnore = (function ($isUncaught, $exception, $payload) {
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
                if ($source instanceof \UnexpectedValueException &&
                    stripos($source->getMessage(), "Untrusted Host") !== false) {
                    return true;
                }

                // Don't sent HWI OAuth No resource owner 
                // Verify: GET -UsEd https://wearesosure.com/login/LoginForm.jsp
                if ($source instanceof \RuntimeException &&
                    stripos($source->getMessage(), "No resource owner") !== false) {
                    return true;
                }
            }

            return false;
        });
    }
}
