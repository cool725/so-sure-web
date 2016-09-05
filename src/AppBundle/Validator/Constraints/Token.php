<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class Token extends Constraint
{
    public $message = 'The string "%string%" contains an illegal character.';
}
