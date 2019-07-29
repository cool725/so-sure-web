<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Repository\PolicyRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Allows the generation of a report on whether a given set of policies are underpaid, overpaid, or paid to date.
 */
class PaymentStatusCommand extends ContainerAwareCommand
{
    /** @var DocumentManager $dm */
    protected $dm;

    /**
     * Injects the command's dependencies.
     * @param DocumentManager $dm is the document manager used to get access to policies.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * @Override
     */
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

    /**
     * @Override
     */
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
                    $output->writeln('policyId, amountOwed, amountScheduled, paymentStatus');
                } else {
                    $line = array_combine($header, $row);
                    /** @var Policy $policy */
                    $policy = $policyRepository->findOneBy(['_id' => $line['id']]);
                    if (!$policy) {
                        $output->writeln(sprintf(
                            '%s, %s, %s, %s',
                            $line['id'],
                            0,
                            0,
                            'not found'
                        ));
                        continue;
                    }
                    $owed = $policy->getOutstandingPremiumToDate(null, true) - $policy->getPendingBacsPaymentsTotal();
                    $scheduled = $policy->getOutstandingScheduledPaymentsAmount();
                    $owedString = '';
                    if ($owed > 0) {
                        $owedString = 'underpaid';
                    } elseif ($owed < 0) {
                        $owedString = 'overpaid';
                    } else {
                        $owedString = 'paid';
                    }
                    $output->writeln(sprintf(
                        '%s, %s, %s, %s',
                        $policy->getId(),
                        $owed,
                        $scheduled,
                        $owedString
                    ));
                }
            }
        }
    }
}
