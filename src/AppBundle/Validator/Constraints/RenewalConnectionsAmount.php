<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class RenewalConnectionsAmount extends Constraint
{
    public $message = 'You have too many connections selected and have exceeded the maximum reward pot.';
}
