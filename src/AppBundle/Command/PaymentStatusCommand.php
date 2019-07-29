<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PaymentStatusCommand extends ContainerAwareCommand
{
    /** @var DocumentManager $dm */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:payment:status')
            ->setDescription('Takes a list of policies and tells you if they are paid, underpaid, or overpaid')
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'csv file to load policy ids from'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var PolicyRepository $policyRepository */
        $policyRepository = $this->dm->getRepository(Policy::class);
        $filename = $input->getOption('file');
        $header = null;
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $header = $row;
                    $output->writeln('policyId, amountOwed, paymentStatus');
                } else {
                    $line = array_combine($header, $row);
                    $policy = $policyRepository->findOneBy(['_id' => $line['id']]);
                    if (!$policy) {
                        $output->writeln(sprintf(
                            '%s, %s, %s',
                            $line['id'],
                            0,
                            'not found'
                        ));
                        continue;
                    }
                    $owed = $policy->getOutstandingPremiumToDate(null, true);
                    $owedString = '';
                    if ($owed > 0) {
                        $owedString = 'underpaid';
                    } elseif ($owed < 0) {
                        $owedString = 'overpaid';
                    } else {
                        $owedString = 'paid';
                    }
                    $output->writeln(sprintf(
                        '%s, %s, %s',
                        $policy->getId(),
                        $owed,
                        $owedString
                    ));
                }
            }
        }
    }
}
