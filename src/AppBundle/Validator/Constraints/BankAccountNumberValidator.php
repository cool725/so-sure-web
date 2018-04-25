<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\BacsTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class BankAccountNumberValidator extends ConstraintValidator
{
    use BacsTrait;

    public function validate($value, Constraint $constraint)
    {
        /** @var BankAccountNumber $bankAccountNumberConstraint */
        $bankAccountNumberConstraint = $constraint;

        if (mb_strlen($value) == 0) {
            return;
        }

        if (!$this->validateAccountNumber($value)) {
            $this->context->buildViolation($bankAccountNumberConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
