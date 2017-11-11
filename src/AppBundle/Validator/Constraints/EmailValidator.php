<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class EmailValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
