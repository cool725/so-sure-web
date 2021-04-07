<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Service\CheckoutService;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Document\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Does checkout refunds.
 */
class RefundCommand extends ContainerAwareCommand
{
    /**
     * @var DocumentManager $dm
     */
    protected $dm;

    /**
     * @var CheckoutService $checkout
     */
    protected $checkout;

    /**
     * Builds the command object.
     * @param DocumentManager $dm       is used to document stuff.
     * @param CheckoutService $checkout handles refunds.
     */
    public function __construct(DocumentManager $dm, CheckoutService $checkout)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->checkout = $checkout;
    }

    /**
     * @InheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:refund')
            ->setDescription('Finds the latest viable payments on the given policies and refunds them')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date from which to find the most recent payment to refund. Defaults to now.'
            )
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'without this no changes are persisted'
            )
            ->addArgument(
                'ids',
                InputArgument::IS_ARRAY,
                'ids of policies to refund'
            );
    }

    /**
     * @InheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = new \DateTime($input->getOption('date') ?: null);
        $wet = $input->getOption('wet') == true;
        $ids = $input->getArgument('ids');
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        foreach ($ids as $id) {
            /** @var Policy $policy */
            $policy = $policyRepo->findOneBy(['_id' => $id]);
            if (!$policy) {
                $output->writeln("<error>{$id} is not a valid policy id</error>");
                continue;
            }
            /** @var CheckoutPayment|null $payment */
            $payment = $policy->getLastSuccessfulUserPaymentCredit($date, 'checkout');
            if (!$payment) {
                $output->writeln("<error>Policy {$id} has no payment to refund</error>");
                continue;
            }
            if ($wet) {
                $this->checkout->refund($payment);
            }
            $output->writeln(sprintf(
                'refunding payment %s value %f for policy %s - %s',
                $payment->getId(),
                $payment->getAmount(),
                $id,
                $wet ? 'wetly' : 'dryly'
            ));
        }
    }
}
