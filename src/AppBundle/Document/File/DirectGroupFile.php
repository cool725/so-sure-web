<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document()
 */
class DirectGroupFile extends S3File
{
    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $success;

    public function isSuccess()
    {
        return $this->success;
    }

    public function setSuccess($success)
    {
        $this->success = $success;
    }
}
