<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class AlphanumericSpaceDotValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        /** @var AlphanumericSpaceDot $alphanumericSpaceDotContraint */
        $alphanumericSpaceDotContraint = $constraint;

        if (is_object($value)) {
            throw new \Exception(sprintf('Excpected string %s', json_encode($value)));
        }

        if (!preg_match($this->getRegex(), $value, $matches)) {
            $this->context->buildViolation($alphanumericSpaceDotContraint->message)
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
        $expr = preg_quote('-.,;:+():_£&@*!?^#"%=\'’[]{}');
        $regex = sprintf('[ %s\/a-zA-Z0-9\x{00C0}-\x{017F}]*', $expr);
        if ($exact) {
            $regex = sprintf('/^%s$/u', $regex);
        } else {
            $regex = sprintf('/%s/u', $regex);
        }

        return $regex;
    }
}
