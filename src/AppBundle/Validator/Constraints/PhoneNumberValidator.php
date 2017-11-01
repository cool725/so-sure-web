<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PhoneNumberValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        // allow empty string in validation
        if (strlen($value) == 0) {
            return;
        }
        $value = preg_replace('/[\s\.\+\-\(\)]*/', '', $value);
        if (!preg_match('/^[\d]{8,20}$/', $value, $matches)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
