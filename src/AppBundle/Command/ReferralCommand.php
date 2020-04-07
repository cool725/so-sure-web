<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use AppBundle\Service\ReferralService;

class ReferralCommand extends ContainerAwareCommand
{
    /** @var ReferralService */
    protected $referralService;

    public function __construct(ReferralService $referralService)
    {
        parent::__construct();
        $this->referralService = $referralService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:referrals')
            ->setDescription('Handle and manage referrals')
            ->addOption(
                'report',
                null,
                InputOption::VALUE_NONE,
                'Generate Referrals report'
            )
            ->addOption(
                's3',
                null,
                InputOption::VALUE_NONE,
                'Upload to s3'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $report = true === $input->getOption('report');
        $s3 = true === $input->getOption('s3');

        $date = new \DateTime();

        $result = $this->referralService->processReferrals($date);

        $output->writeln(sprintf('%s referral bonuses applied', $result['Applied']));
        $output->writeln(sprintf('%s referral bonuses pending renewal', $result['Pending']));

        if ($report) {
            $output->writeln('Generate report');
        }
    }
}
