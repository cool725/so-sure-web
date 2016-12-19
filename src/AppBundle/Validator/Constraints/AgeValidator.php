<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class AgeValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        $birthday = null;
        $diff = null;
        if ($value instanceof \DateTime) {
            $birthday = $value;
        } else {
            try {
                if (strlen($value) == 0) {
                    return;
                }
                $birthday = \DateTime::createFromFormat('d/m/Y', $value);
            } catch (\Exception $e) {
                // Do Nothing
                \AppBundle\Classes\NoOp::noOp([$e]);
            }
        }

        if ($birthday) {
            $now = new \DateTime();
            $diff = $now->diff($birthday);
        }

        if (!$diff || $diff->y < 18) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('%string%', $birthday ? $birthday->format(\DateTime::ATOM) : 'not present')
                ->addViolation();
        }
    }
}
