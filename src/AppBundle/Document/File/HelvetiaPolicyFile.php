<?php

namespace AppBundle\Document\File;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * A file to store in S3 regarding a helvetia policy.
 * @MongoDB\Document()
 */
class HelvetiaPolicyFile extends S3File
{

}
