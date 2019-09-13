<?php

namespace AppBundle\Repository;

use AppBundle\Document\Postcode;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PostcodeRepository extends DocumentRepository
{
    public function getAll()
    {
        return $this->findAll();
    }

    public function getPostcodes()
    {
        return $this->findBy(["type" => Postcode::PostCode]);
    }

    public function getOutCodes()
    {
        return $this->findBy(["type" => Postcode::OutCode]);
    }

    public function getPostcodeIsAnnualOnly($postcode)
    {
        $postcodeDoc = new Postcode();
        $postcodeDoc->setPostcode($postcode);
        $outCode = $postcodeDoc->getOutCode();
        $outCodes = $this->findBy(["type" => "outcode", "postcode" => $outCode]);
        if (count($outCodes) > 0) {
            return true;
        }
        $canonicalPostcode = $postcodeDoc->getPostcodeCanonical();
        $postcodes = $this->findBy(["postcodeCanonical" => $canonicalPostcode]);
        if (count($postcodes)) {
            return true;
        }
        return false;
    }
}
