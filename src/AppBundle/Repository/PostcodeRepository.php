<?php

namespace AppBundle\Repository;

use AppBundle\Document\Postcode;
use AppBundle\Document\Reward;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PostcodeRepository extends DocumentRepository
{
    public function getAll()
    {
        return $this->findAll();
    }

    public function getPostcodes()
    {
        return $this->findBy(["type", Postcode::PostCode]);
    }

    public function getOutCodes()
    {
        return $this->findBy(["type", Postcode::OutCode]);
    }

    public function getPostcodeIsAnnualOnly($postcode)
    {
        $postcode = new Postcode();
        $postcode->setPostcode($postcode);
        $outCode = $postcode->getOutCode();
        $outCodes = $this->findBy(["type" => "outcode", "postcode" => $outCode]);
        if (count($outCodes) > 0) {
            return true;
        }
        $canonicalPostcode = $postcode->getPostcodeCanonical();
        $postcodes = $this->findBy(["postcode" => $postcode]);
        if (count($postcodes)) {
            return true;
        }
        return false;
    }
}
