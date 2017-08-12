<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class SortCodeValidator extends ConstraintValidator
{
    protected $banking;

    public function __construct($banking)
    {
        $this->banking = $banking;
    }

    public function validate($value, Constraint $constraint)
    {
        if (strlen($value) == 0) {
            return;
        }

        if (!$this->banking->validateSortCode($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
