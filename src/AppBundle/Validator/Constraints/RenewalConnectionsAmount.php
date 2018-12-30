<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class RenewalConnectionsAmount extends Constraint
{
    // @codingStandardsIgnoreStart
    public $message = 'You have too many connections selected for your policy "%string%" and have exceeded the maximum reward pot.';
    // @codingStandardsIgnoreEnd
}
