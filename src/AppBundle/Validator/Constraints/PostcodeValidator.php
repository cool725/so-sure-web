<?php

namespace AppBundle\Validator\Constraints;

use CensusBundle\Service\SearchService;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PostcodeValidator extends ConstraintValidator
{
    protected $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function validate($value, Constraint $constraint)
    {
        /** @var Postcode $postcodeConstraint */
        $postcodeConstraint = $constraint;

        if (mb_strlen($value) == 0) {
            return;
        }

        if (!$this->searchService->validatePostcode($value)) {
            $this->context->buildViolation($postcodeConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
