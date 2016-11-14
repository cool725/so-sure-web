<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UkMobile extends Constraint
{
    public $message = '"%string%" does not appear to be a valid UK Mobile Number';
}
