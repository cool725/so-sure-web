<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class FullNameValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var FullName $fullNameConstraint */
        $fullNameConstraint = $constraint;

        // allow blank string - different validations should be used for null/not null
        if (mb_strlen(trim($value)) == 0) {
            return;
        }

        // Expected 1 space
        $parts = explode(" ", trim($value));
        if (!preg_match($this->getRegex(), $value, $matches) || count($parts) != 2) {
            $this->context->buildViolation($fullNameConstraint->message)
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
        $expr = preg_quote("'-");
        $regex = sprintf('[ %sa-zA-Z0-9\x{00C0}-\x{017F}]*', $expr);
        if ($exact) {
            $regex = sprintf('/^%s$/u', $regex);
        } else {
            $regex = sprintf('/%s/u', $regex);
        }

        return $regex;
    }
}
