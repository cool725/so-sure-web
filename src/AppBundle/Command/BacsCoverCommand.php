<?php

namespace AppBundle\Command;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\CheckoutService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

/**
 * Command that handles bacs cover payments.
 */
class BacsCoverCommand extends ContainerAwareCommand
{
    /** @var DocumentManager */
    protected $dm;

    /** @var CheckoutService */
    protected $checkoutService;

    /**
     * Creates the command object and injects the dependencies.
     * @param DocumentManager $dm              is used to query the database and stuff.
     * @param CheckoutService $checkoutService is used to do checkout stuff.
     */
    public function __construct(DocumentManager $dm, CheckoutService $checkoutService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->checkoutService = $checkoutService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:bacs:cover')
            ->setDescription('Finds successful bacs payments that were covered by checkout payments and refunds')
            ->addOption('wet', null, InputOption::VALUE_NONE, 'actually persist changes');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $wet = $input->getOption('wet');
        /** @var BacsPaymentRepository $bacsPaymentRepo */
        $bacsPaymentRepo = $this->dm->getRepository(BacsPayment::class);
        $ripePayments = $bacsPaymentRepo->findReadyCoveredPayments(new \DateTime());
        /** @var BacsPayment $payment */
        foreach ($ripePayments as $payment) {
            $output->writeln(sprintf(
                'policy %s payment %s date %s amount %f',
                $payment->getPolicy()->getId(),
                $payment->getId(),
                $payment->getDate()->format('Y-m-d H:i'),
                $payment->getAmount()
            ));
            if ($wet) {
                $this->checkoutService->refund($payment->getCoveredBy());
                $payment->setCoveringPaymentRefunded(true);
                $this->dm->flush();
            }
        }
    }
}
