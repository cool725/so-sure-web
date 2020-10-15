<?php

namespace AppBundle\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 */
class PolicyClaimAllowed extends Constraint
{
    public $message = 'This policy does not cover this claim';

    /**
     * @inheritDoc
     */
    public function getTargets()
    {
        return Constraint::CLASS_CONSTRAINT;
    }
}
