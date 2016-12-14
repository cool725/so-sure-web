<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class FullName extends Constraint
{
    public $message = 'Expected Given Name Surname (2 names only)';
}
