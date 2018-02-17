<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class SerialNumberValidator extends ConstraintValidator
{
    protected $imei;

    public function __construct($imei)
    {
        $this->imei = $imei;
    }

    public function validate($value, Constraint $constraint)
    {
        // allow empty string in validation
        if (strlen($value) == 0) {
            return;
        }
        if (!$this->imei->isAppleSerialNumber($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
