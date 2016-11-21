<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;

class SCodeCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:scode')
            ->setDescription('Show/Update scode link')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Update scode link in Branch'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lines = [];
        $policyNumber = $input->getOption('policyNumber');

        $dm = $this->getContainer()->get('doctrine.odm.mongodb.document_manager');
        $policyRepo = $dm->getRepository(Policy::class);
        $scodeRepo = $dm->getRepository(SCode::class);

        if ($policyNumber) {
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            $scode = $policy->getStandardSCode();
            if (!$scode) {
                throw new \Exception(sprintf('Unable to find scode for policy %s', $policyNumber));
            }
            $this->printSCode($output, $scode);
            $shareLink = $this->getContainer()->get('app.branch')->generateSCode($scode->getCode());
            $scode->setShareLink($shareLink);
            $dm->flush();
            $this->printSCode($output, $scode);
        } else {
            $scodes = $scodeRepo->getLinkPrefix('https://goo.gl');
            foreach ($scodes as $scode) {
                $this->printSCode($output, $scode);
            }
        }

        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }

    private function printSCode($output, $scode)
    {
        $output->writeln(sprintf(
            '%s %s %s',
            $scode->getPolicy()->getPolicyNumber(),
            $scode->getCode(),
            $scode->getShareLink()
        ));        
    }
}
