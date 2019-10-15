<?php

namespace AppBundle\Command;

use AppBundle\Service\SmsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Policy;

class SmsCommand extends ContainerAwareCommand
{
    /** @var DocumentManager   */
    protected $dm;

    /** @var SmsService */
    protected $smsService;

    public function __construct(DocumentManager $dm, SmsService $smsService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->smsService = $smsService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:sms')
            ->setDescription('Send sms to user')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'policyNumber'
            )
            ->addOption(
                'attempt',
                null,
                InputOption::VALUE_REQUIRED,
                '2 to 4'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyNumber = $input->getOption('policyNumber');
        $attempt = $input->getOption('attempt');

        if ($policyNumber && $attempt) {
            $repo = $this->dm->getRepository(Policy::class);
            /** @var Policy $policy */
            $policy = $repo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy %s', $policyNumber));
            }

            $smsTemplate = sprintf('AppBundle:Sms:bacs/failedPayment-%d.txt.twig', $attempt);

            $this->smsService->sendUser($policy, $smsTemplate, ['policy' => $policy]);
        } else {
            $output->writeln('Nothing to do');
        }
    }
}
