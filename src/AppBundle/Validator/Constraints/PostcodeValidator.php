<?php

namespace AppBundle\Validator\Constraints;

use AppBundle\Document\PostcodeTrait;
use AppBundle\Service\PCAService;
use CensusBundle\Service\SearchService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PostcodeValidator extends ConstraintValidator
{
    use PostcodeTrait;

    /** @var SearchService */
    protected $searchService;
    /** @var PCAService */
    protected $pcaService;
    /** @var DocumentManager */
    protected $dm;

    public function __construct(SearchService $searchService, PCAService $pcaService, DocumentManager $dm)
    {
        $this->searchService = $searchService;
        $this->pcaService = $pcaService;
        $this->dm = $dm;
    }

    public function validate($value, Constraint $constraint)
    {
        /** @var Postcode $postcodeConstraint */
        $postcodeConstraint = $constraint;

        if (mb_strlen($value) == 0) {
            return;
        }

        // If the postcode is not in our local db, use pca to validate
        if (!$this->searchService->validatePostcode($value)) {
            if ($this->pcaService->validatePostcode($value)) {
                $postCode = new \CensusBundle\Document\Postcode();
                $postCode->setPostcode($this->normalizePostcodeForDisplay($value));
                $this->dm->persist($postCode);
                $this->dm->flush();
            }
        }

        if (!$this->searchService->validatePostcode($value)) {
            $this->context->buildViolation($postcodeConstraint->message)
                ->setParameter('%string%', $value)
                ->addViolation();
        }
    }
}
