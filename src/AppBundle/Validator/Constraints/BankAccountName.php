<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class BankAccountName extends Constraint
{
    public $message = '%string% does not appear to match the policy holder name.';
}
