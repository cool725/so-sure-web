<?php
namespace CensusBundle\Service;

use AppBundle\Document\PostcodeTrait;
use CensusBundle\Document\Postcode;
use CensusBundle\Document\OutputArea;
use CensusBundle\Document\Income;
use CensusBundle\Repository\IncomeRepository;
use CensusBundle\Repository\OutputAreaRepository;
use CensusBundle\Repository\PostCodeRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use GeoJson\Geometry\Point;

class SearchService
{
    use PostcodeTrait;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function validatePostcode($code)
    {
        $code = $this->normalizePostcodeForDisplay($code);
        if ($code == "BX1 1LT") {
            return true;
        } elseif ($code == "ZZ99 3CZ") {
            return false;
        }

        /** @var PostCodeRepository $postcodeRepo */
        $postcodeRepo = $this->dm->getRepository(PostCode::class);

        $postcode = $postcodeRepo->findOneBy(['Postcode' => $code]);
        if (!$postcode) {
            return false;
        }

        return true;
    }

    public function getPostcode($code)
    {
        /** @var PostCodeRepository $postcodeRepo */
        $postcodeRepo = $this->dm->getRepository(PostCode::class);

        return $postcodeRepo->findOneBy(['Postcode' => $code]);
    }

    public function findNearest($code)
    {
        $postcode = $this->getPostcode($code);
        if (!$postcode) {
            return null;
        }
        $searchQuery = $this->dm->createQueryBuilder('CensusBundle:Census')
            ->field('Location')
            ->geoNear($postcode->getLocation()->asPoint())
            ->spherical(true)
            // in meters
            ->distanceMultiplier(0.001)
            ->getQuery();

        return $searchQuery->getSingleResult();
    }

    public function findNearestPostcode($code, $excluded = null)
    {
        if (!$excluded) {
            $excluded = [$code];
        }
        $postcode = $this->getPostcode($code);
        if (!$postcode) {
            return null;
        }

        $searchQuery = $this->dm->createQueryBuilder('CensusBundle:Postcode')
            ->field('Postcode')->notIn($excluded)
            ->field('Location')
            ->geoNear($postcode->getLocation()->asPoint())
            ->spherical(true)
            // in meters
            ->distanceMultiplier(0.001)
            ->getQuery();

        return $searchQuery->getSingleResult();
    }

    public function findOutputArea($code)
    {
        /** @var OutputAreaRepository $outputAreaRepo */
        $outputAreaRepo = $this->dm->getRepository(OutputArea::class);

        return $outputAreaRepo->findOneBy(['Postcode' => $code]);
    }

    public function findIncome($code, $excluded = null)
    {
        $outputArea = $this->findOutputArea($code);
        if (!$outputArea) {
            if (count($excluded) < 3) {
                if (!$excluded) {
                    $excluded = [];
                }
                $excluded = array_merge($excluded, [$code]);
                if ($nearest = $this->findNearestPostcode($code, $excluded)) {
                    return $this->findIncome($nearest->getPostcode(), $excluded);
                }
            }
            return null;
        }

        /** @var IncomeRepository $incomeRepo */
        $incomeRepo = $this->dm->getRepository(Income::class);

        return $incomeRepo->findOneBy(['MSOA' => $outputArea->getMSOA()]);
    }
}
