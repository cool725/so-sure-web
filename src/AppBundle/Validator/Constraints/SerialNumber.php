<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class SerialNumber extends Constraint
{
    public $message = '"%string%" is not a valid serial number.';
}
