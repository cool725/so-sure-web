<?php

namespace AppBundle\Listener;

use AppBundle\Exception\ValidationException;
use AppBundle\Event\ObjectEvent;

class ValidationListener
{
    protected $validator;
    protected $logger;

    public function __construct($validator, $logger)
    {
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function onValidateEvent(ObjectEvent $event)
    {
        $this->checkValidation($event->getObject());
    }

    private function checkValidation($document)
    {
        $errors = $this->validator->validate($document);
        if (count($errors) > 0) {
            $this->logger->error(sprintf(
                'Failed to validate object %s (err: %s)',
                get_class($document),
                (string) $errors
            ));

            throw new ValidationException((string) $errors);
        }
    }
}
