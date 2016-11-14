<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class Imei extends Constraint
{
    public $message = '"%string%" is not a valid imei number.';
}
