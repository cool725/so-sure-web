<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class LloydsFileRepository extends S3FileRepository
{
}
