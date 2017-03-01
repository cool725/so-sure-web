<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class UkMobile extends Constraint
{
    public $message = 'Sorry, we only support UK Residents, please enter a valid UK Mobile Number.';
}
