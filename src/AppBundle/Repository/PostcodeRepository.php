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
        return $this->findBy(["type" => Postcode::POSTCODE]);
    }

    public function getOutCodes()
    {
        return $this->findBy(["type" => Postcode::OUTCODE]);
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

    public function getPostcodeIsBanned($postcode)
    {
        $postcodeDoc = new Postcode();
        $postcodeDoc->setPostcode($postcode);
        $outcode = $postcodeDoc->getOutCode();
        $outcodes = $this->findBy(['type' => 'outcode', 'postcode' => $outcode, 'banned' => true]);
        $canonicalPostcode = $postcodeDoc->getPostcodeCanonical();
        $postcodes = $this->findBy(['type' => 'postcode', "postcodeCanonical" => $canonicalPostcode, 'banned' => true]);
        if ((count($outcodes) + count($postcodes)) > 0) {
            return true;
        } else {
            return false;
        }
    }
}
