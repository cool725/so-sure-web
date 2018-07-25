<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\Claim;

/**
 * @MongoDB\Document()
 */
class S3ClaimFile extends S3File
{

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Claim", inversedBy="files")
     * @Gedmo\Versioned
     * @var Claim
     */
    protected $claim;

    public function setClaim(Claim $claim)
    {
        $this->claim = $claim;
    }

    public function getClaim()
    {
        return $this->claim;
    }
}
