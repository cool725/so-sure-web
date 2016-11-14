<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class Postcode extends Constraint
{
    public $message = '%string% is not a valid postcode';
}
