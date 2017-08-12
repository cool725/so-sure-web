<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class SortCode extends Constraint
{
    public $message = '%string% is not a valid sort code';
}
