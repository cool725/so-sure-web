<?php

namespace AppBundle\Repository;

use AppBundle\Document\Offer;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

/**
 * Provides queries for finding offers.
 */
class OfferRepository extends BaseDocumentRepository
{
    use DateTrait;
}
