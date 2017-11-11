<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class Email extends Constraint
{
    public $message = 'This value is not a valid email address.';
}
