<?php

namespace AppBundle\Command;

use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use Doctrine\MongoDB\EagerCursor;
use Doctrine\ODM\MongoDB\Cursor;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\MongoDBException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PolicyUpdatePaymentCommand extends ContainerAwareCommand
{
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
        try {
            $result = $this->getOutstanding();
        } catch (MongoDBException $e) {
            $output->writeln("Could not find policies, the following error occurred: {$e->getMessage()}");
            exit($e->getTraceAsString());
        }
        /** @var SalvaPhonePolicy $policy */
        while ($result->hasNext()) {
            /** @var SalvaPhonePolicy $next */
            $next = $result->getNext();
            $id = $next->getId();
            $output->writeln("Updating $id as it is missing paymentMethod");
            $previousId = $next->getPreviousPolicy()->getId();
            /** @var SalvaPhonePolicy $oldPaymethod */
            $oldPayMethod = null;
            try {
                $oldPayMethod = $this->getPreviousPayMethod($previousId);
            } catch (MongoDBException $e) {
                $output->writeln("Could not find previous policy for $id, error occurred: {$e->getMessage()}");
            }
            if ($oldPayMethod === null) {
                $output->writeln("Policy $id has no paymentMethod and the previous policy does not have one either.");
            } else {
                try {
                    $this->updateNewPayMethodWithOld($next->getId(), $oldPayMethod);
                } catch (MongoDBException $e) {
                    $output->writeln("Update of paymentMethod for $id failed with the reason {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Finds any active policies that do not have a payment method or do not have a bank account on the policy.
     * This will only work for policies that have a previousPolicy i.e. a renewal.
     *
     * @return mixed
     * @throws MongoDBException
     */
    private function getOutstanding()
    {
        $qb = $this->dm->createQueryBuilder(Policy::class);
        return $qb->field('status')->equals('active')
            ->field('previousPolicy')->exists(true)
            ->addOr(
                $qb->expr()
                    ->addOr($qb->expr()->field('paymentMethod')->exists(false))
                    ->addOr($qb->expr()->field('paymentMethod')->equals(''))
            )
            ->addOr(
                $qb->expr()->field('paymentMethod.type')->equals('bacs')
                    ->addOr($qb->expr()->field('paymentMethod.bankAccount')->exists(false))
                    ->addOr($qb->expr()->field('paymentMethod.bankAccount')->equals(''))
            )
            ->addOr(
                $qb->expr()
                    ->field('paymentMethod.type')->notEqual('bacs')
                    ->addOr($qb->expr()->field('paymentMethod.cardToken')->exists(false))
                    ->addOr($qb->expr()->field('paymentMethod.cardToken')->equals(''))
            )
            ->getQuery()
            ->execute();
    }

    /**
     * Using the previousPolicy _id on the policies that are missing payment data,
     * find the paymentMethod from their previous policy.
     *
     * @param string $id
     * @return PaymentMethod|null
     * @throws MongoDBException
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
     * @param string        $id
     * @param PaymentMethod $oldPayMethod
     * @return mixed
     * @throws MongoDBException
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
