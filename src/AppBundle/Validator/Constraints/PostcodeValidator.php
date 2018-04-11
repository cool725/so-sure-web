<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PostcodeValidator extends ConstraintValidator
{
    protected $address;

    public function __construct($address)
    {
        $this->address = $address;
    }

    public function validate($value, Constraint $constraint)
    {
        if (mb_strlen($value) == 0) {
            return;
        }

        if (!$this->address->validatePostcode($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
