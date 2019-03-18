<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents a backup of an uploaded Optimize CSV file.
 * @MongoDB\Document
 * @Vich\Uploadable
 */
class ManualAffiliateFile extends UploadFile
{
    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     * @Vich\UploadableField(mapping="adminS3Mapping", fileNameProperty="fileName")
     * @var File
     */
    protected $file;

    /**
     * The processed version of this file.
     * @MongoDB\ReferenceOne(targetDocument="ManualAffiliateProcessedFile", inversedBy="source")
     * @Gedmo\Versioned
     * @var ManualAffiliateProcessedFile
     */
    protected $processed;

    public function getS3FileName()
    {
        return sprintf("optimizeCsv/%s", (new \DateTime())->format('U'));
    }

    public function getProcessed(): ManualAffiliateProcessedFile
    {
        return $this->processed;
    }

    public function setProcessed(ManualAffiliateProcessedFile $processed)
    {
        $this->processed = $processed;
    }
}
