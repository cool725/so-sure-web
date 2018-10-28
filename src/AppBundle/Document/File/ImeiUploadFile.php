<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;
use AppBundle\Document\Policy;

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

    protected $policy;

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    /**
     * @return string
     */
    public function getS3FileName()
    {
        $now = \DateTime::createFromFormat('U', time());

        return sprintf(
            'imei/%s/imei-%d-%02d-%02d-%s',
            $this->policy->getId(),
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $now->format('U')
        );
    }
}
