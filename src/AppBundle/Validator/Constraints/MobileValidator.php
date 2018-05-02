<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class MobileValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var Mobile $mobileConstraint */
        $mobileConstraint = $constraint;
        // allow empty string in validation
        if (mb_strlen($value) == 0) {
            return;
        }
        if (!preg_match('/^(00447[1-9]\d{8,8}|\+447[1-9]\d{8,8})$/', $value, $matches)) {
            $this->context->buildViolation($mobileConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
