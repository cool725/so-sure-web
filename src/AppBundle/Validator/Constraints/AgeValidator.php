<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class AgeValidator extends ConstraintValidator
{
    const MIN_AGE = 18;
    const MAX_AGE = 150;

    public function validate($value, Constraint $constraint)
    {
        /** @var Age $ageContraint */
        $ageContraint = $constraint;

        $birthday = null;
        $diff = null;
        if ($value instanceof \DateTime) {
            $birthday = $value;
        } else {
            try {
                if (mb_strlen($value) == 0) {
                    return;
                }
                $birthday = \DateTime::createFromFormat('d/m/Y', $value);
            } catch (\Exception $e) {
                // Do Nothing
                \AppBundle\Classes\NoOp::ignore([$e]);
            }
        }

        if ($birthday) {
            $now = new \DateTime();
            $diff = $now->diff($birthday);
        }

        if (!$diff || $diff->y < self::MIN_AGE || $diff->y > self::MAX_AGE) {
            $this->context->buildViolation($ageContraint->message)
                ->setParameter('%string%', $birthday ? $birthday->format(\DateTime::ATOM) : 'not present')
                ->addViolation();
        }
    }
}
