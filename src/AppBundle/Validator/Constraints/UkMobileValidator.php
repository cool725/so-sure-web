<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class UkMobileValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var UkMobile $ukMobileConstraint */
        $ukMobileConstraint = $constraint;

        if (is_object($value)) {
            throw new \Exception(sprintf('Expected string %s', json_encode($value)));
        }
        // allow empty string in validation
        if (mb_strlen($value) == 0) {
            return;
        }

        $value = str_replace(' ', '', $value);
        if (!preg_match($this->getRegex(), $value, $matches)) {
            $this->context->buildViolation($ukMobileConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }

    public function conform($value)
    {
        preg_match_all($this->getRegex(false), $value, $matches);
        return implode('', $matches[0]);
    }

    private function getRegex($exact = true)
    {
        // main concerns are around [] (php array) and $ (mongodb expression)
        $regex = '(00447[1-9]\d{8,8}|\+447[1-9]\d{8,8}|07[1-9]\d{8,8})';
        if ($exact) {
            $regex = sprintf('/^%s$/u', $regex);
        } else {
            $regex = sprintf('/%s/u', $regex);
        }

        return $regex;
    }
}
