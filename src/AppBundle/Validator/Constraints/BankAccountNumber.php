<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class BankAccountNumber extends Constraint
{
    public $message = '%string% is not a valid uk bank account number';
}
