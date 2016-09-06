<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TokenValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        // main concerns are around [] (php array) and $ (mongodb expression)
        $expr = preg_quote('-.,;:+():_£&@*!^#"%');
        $regex = sprintf('/^[ %s\/a-zA-Z0-9]*$/', $expr);
        if (!preg_match($regex, $value, $matches)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
