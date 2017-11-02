<?php
namespace CensusBundle\Service;

use CensusBundle\Document\Postcode;
use CensusBundle\Document\OutputArea;
use CensusBundle\Document\Income;
use GeoJson\Geometry\Point;

class SearchService
{
    protected $dm;

    /**
     * @param $router
     */
    public function __construct($dm)
    {
        $this->dm = $dm;
    }

    public function getPostcode($code)
    {
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

        $incomeRepo = $this->dm->getRepository(Income::class);

        return $incomeRepo->findOneBy(['MSOA' => $outputArea->getMSOA()]);
    }
}
