<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Common\Persistence\ManagerRegistry;
use AppBundle\Document\Sequence;

class SequenceService
{
    const SEQUENCE_PHONE = 'phone-policy';
    const SEQUENCE_PHONE_INVALID = 'phone-policy-invalid';

    const SEQUENCE_INVOICE = 'invoice';
    const SEQUENCE_INVOICE_INVALID = 'invoice-invalid';

    const SEQUENCE_BACS_REFERENCE = 'bacs-reference';
    const SEQUENCE_BACS_REFERENCE_INVALID = 'bacs-reference-invalid';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    public function getSequenceId($name)
    {
        $sequence = $this->dm->getDocumentCollection(Sequence::class);
        $result = $sequence->findAndUpdate(
            ['_id' => $name],
            ['$inc' => ['seq' => 1]],
            ['new' => true, 'upsert' => true]
        );
        if ($result['_id'] != $name) {
            throw new \Exception('Unable to generate sequenceId');
        }

        return $result['seq'];
    }
}
