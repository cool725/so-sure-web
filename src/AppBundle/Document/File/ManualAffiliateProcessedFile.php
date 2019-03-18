<?php

namespace AppBundle\Document\File;

use Gedmo\Mapping\Annotation as Gedmo;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Represents a backup of a processed Optimize CSV file.
 * @MongoDB\Document()
 */
class ManualAffiliateProcessedFile extends S3File
{
    /**
     * The unmodified version of this file.
     * @MongoDB\ReferenceOne(targetDocument="ManualAffiliateFile", inversedBy="processed")
     * @Gedmo\Versioned
     * @var ManualAffiliateFile
     */
    protected $source;

    public function getSource(): ManualAffiliateFile
    {
        return $this->source;
    }

    public function setSource(ManualAffiliateFile $source)
    {
        $this->source = $source;
    }
}
