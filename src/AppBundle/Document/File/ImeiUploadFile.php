<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * @MongoDB\Document()
 * @Vich\Uploadable
 */
class ImeiUploadFile extends UploadFile
{
    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="policyS3Mapping", fileNameProperty="fileName")
     *
     * @var File
     */
    protected $file;

   /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = new \DateTime();

        return sprintf(
            'imei-%d-%02d-%02d-%s',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $now->format('U')
        );
    }
}
