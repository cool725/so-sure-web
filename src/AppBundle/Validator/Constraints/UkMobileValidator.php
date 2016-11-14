<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UkMobileValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        // allow empty string in validation
        if (strlen($value) == 0) {
            return;
        }
        if (!preg_match('/^(00447[1-9]\d{8,8}|\+447[1-9]\d{8,8}|07[1-9]\d{8,8})$/', $value, $matches)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
