<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class AlphanumericSpaceDot extends Constraint
{
    public $message = <<<EOD
The string "%string%" contains an illegal character: it can only contain letters, numbers, spaces, dots, etc
EOD;
}
