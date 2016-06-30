<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

/**
 * @MongoDB\Document()
 * @Vich\Uploadable
 */
class JudoFile extends UploadFile
{
    /**
     * NOTE: This is not a mapped field of entity metadata, just a simple property.
     *
     * @Vich\UploadableField(mapping="judo", fileNameProperty="fileName")
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
            'judo-%d-%02d-%s',
            $this->getDate()->format('Y'),
            $this->getDate()->format('m'),
            $now->format('U')
        );
    }
}
