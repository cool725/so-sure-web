<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\ImeiTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class ImeiValidator extends ConstraintValidator
{
    use ImeiTrait;

    public function validate($value, Constraint $constraint)
    {
        // allow empty string in validation
        if (strlen($value) == 0) {
            return;
        }

        if (!$this->isImei($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
