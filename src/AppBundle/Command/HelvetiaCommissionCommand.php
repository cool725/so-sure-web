<?php

namespace AppBundle\Command;

use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Policy;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Commandline interface to fix Helvetia Commission issues programmatically.
 */
class HelvetiaCommissionCommand extends ContainerAwareCommand
{
    const SERVICE_NAME = 'sosure:helvetia:commission';

    protected static $defaultName = self::SERVICE_NAME;

    /** @var DocumentManager $dm */
    private $dm;

    /**
     * Inserts the dependencies.
     * @param DocumentManager $dm is the document manager that the command will use to query the db.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setDescription('Detects and can also fix incorrect commission on Helvetia Payments')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'The minimum payment date to check on. Format: \'YYYY-MM\'',
                null
            )
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'Automatically fix detected issues when possible',
                null
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dateString = $input->getOption('date');
        $date = $dateString ? \DateTime::createFromFormat('Y-m', $dateString) : new \DateTime();
        $wet = $input->getOption('wet') == true;
        $output->writeln($date->format('Y-m-d'));
    }
}
