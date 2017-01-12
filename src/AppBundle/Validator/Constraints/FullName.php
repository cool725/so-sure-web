<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class FullName extends Constraint
{
    public $message = 'First Name and Surname only.';
}
