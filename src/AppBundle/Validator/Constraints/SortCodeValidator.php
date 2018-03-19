<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\BacsTrait;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class SortCodeValidator extends ConstraintValidator
{
    use BacsTrait;

    public function validate($value, Constraint $constraint)
    {
        if (strlen($value) == 0) {
            return;
        }

        if (!$this->validateSortCode($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
