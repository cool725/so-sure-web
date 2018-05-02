<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class AlphanumericValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var Alphanumeric $alphanumericContraint */
        $alphanumericContraint = $constraint;

        if (!preg_match($this->getRegex(), $value, $matches)) {
            $this->context->buildViolation($alphanumericContraint->message)
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
        $expr = preg_quote('-');
        $regex = sprintf('[%sa-zA-Z0-9\x{00C0}-\x{017F}]*', $expr);
        if ($exact) {
            $regex = sprintf('/^%s$/u', $regex);
        } else {
            $regex = sprintf('/%s/u', $regex);
        }

        return $regex;
    }
}
