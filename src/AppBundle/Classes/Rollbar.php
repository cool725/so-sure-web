<?php
namespace AppBundle\Classes;

class Rollbar extends \RollbarNotifier
{
    public function __construct($config) {
        parent::__construct($config);
        $this->checkIgnore = (function ($isUncaught, $exception, $payload) {
            if ($exception instanceof \RollbarException) {
                $source = $exception->getException();
                return $source instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException ||
                    $source instanceof \Symfony\Component\Routing\Exception\ResourceNotFoundException;
            }
    
            return false;
        });
    }
}
