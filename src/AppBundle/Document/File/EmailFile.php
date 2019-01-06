<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document()
 */
class EmailFile extends S3File
{
}
