<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\ImeiTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class SerialNumberValidator extends ConstraintValidator
{
    use ImeiTrait;

    public function validate($value, Constraint $constraint)
    {
        // allow empty string in validation
        if (mb_strlen($value) == 0) {
            return;
        }

        if (!$this->isAppleSerialNumber($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
