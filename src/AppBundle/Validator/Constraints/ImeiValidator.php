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
        /** @var Imei $imeiConstraint */
        $imeiConstraint = $constraint;

        // allow empty string in validation
        if (mb_strlen($value) == 0) {
            return;
        }

        if (!$this->isImei($value)) {
            $this->context->buildViolation($imeiConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
