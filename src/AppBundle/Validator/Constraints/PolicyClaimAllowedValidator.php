<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\Policy;
use AppBundle\Document\Form\ClaimFnol;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\ValidatorException;

/**
 * Makes sure a given policy can make the given type of claim.
 */
class PolicyClaimAllowedValidator extends ConstraintValidator
{
    private $dm;

    /**
     * Injects dependencies.
     * @param DocumentManager $dm is used to load policies and subvariants to check.
     */
    public function __construct($dm)
    {
        $this->dm = $dm;
    }

    /**
     * @inheritDoc
     */
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof PolicyClaimAllowed) {
            throw new UnexpectedTypeException($constraint, PolicyClaimAllowed::class);
        }
        if (!$value instanceof ClaimFnol) {
            throw new UnexpectedTypeException($value, ClaimFnol::class);
        }
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policy = $policyRepo->find($value->getPolicyNumber());
        if (!$policy) {
            throw new ValidatorException($value, $value->getPolicyNumber());
        }
        $subvariant = $policy->getSubvariant();
        if ($subvariant && !$subvariant->allows($value->getType(), $policy)) {
            $this->context->buildViolation($constraint->message)->atPath('foo')->addViolation();
        } else {
            return true;
        }
    }
}
