<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use Doctrine\MongoDB\EagerCursor;
use Doctrine\ODM\MongoDB\Cursor;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class PolicyUpdatePaymentCommand extends ContainerAwareCommand
{
    use DateTrait;
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:policy:update:payment')
            ->setDescription("Update payment method when current policy doesn\'t have data but previous does.");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var Cursor $result */
        $result = $this->getOutstanding();
        /** @var SalvaPhonePolicy $policy */
        while ($result->hasNext()) {
            /** @var SalvaPhonePolicy $next */
            $next = $result->getNext();
            $previousId = $next->getPreviousPolicy()->getId();
            /** @var SalvaPhonePolicy $oldPaymethod */
            $oldPayMethod = $this->getPreviousPayMethod($previousId);
            /** @var Cursor $update */
            $this->updateNewPayMethodWithOld($next->getId(), $oldPayMethod);
        }
    }

    /**
     * Finds any active policies that do not have a payment method or do not have a bank account on the policy.
     * This will only work for policies that have a previousPolicy i.e. a renewal.
     *
     * @return mixed
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    private function getOutstanding()
    {
        $qb = $this->dm->createQueryBuilder(Policy::class);
        return $qb->field('status')->equals('active')
            ->field('previousPolicy')->exists('true')
            ->addOr($qb->expr()->field('paymentMethod')->equals(''))
            ->addOr($qb->expr()->field('paymentMethod')->exists(false))
            ->addOr($qb->expr()->field('paymentMethod.bankAccount')->equals(''))
            ->addOr($qb->expr()->field('paymentMethod.bankAccount')->exists(false))
            ->getQuery()
            ->execute();
    }

    /**
     * Using the previousPolicy _id on the policies that are missing payment data,
     * find the paymentMethond from their previous policy.
     *
     * @param $id
     * @return \AppBundle\Document\PaymentMethod\PaymentMethod
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    private function getPreviousPayMethod($id)
    {
        /** @var EagerCursor $result */
        $cursor = $this->dm->createQueryBuilder(Policy::class)
            ->field('_id')->equals($id)
            ->getQuery()
            ->execute();
        /** @var SalvaPhonePolicy $doc */
        foreach ($cursor as $doc) {
            return $doc->getPaymentMethod();
        }
    }

    /**
     * Set the old policy's payment method onto the current active policy.
     *
     * @param $id
     * @param $oldPayMethod
     * @return mixed
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    private function updateNewPayMethodWithOld($id, $oldPayMethod)
    {
        return $this->dm->createQueryBuilder(Policy::class)
            ->updateOne()
            ->field('_id')->equals($id)
            ->field('paymentMethod')->set($oldPayMethod)
            ->getQuery()
            ->execute();
    }
}
