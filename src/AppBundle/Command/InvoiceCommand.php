<?php

namespace AppBundle\Command;

use AppBundle\Service\InvoiceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Invoice;

class InvoiceCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:invoice')
            ->setDescription('Create / Regenerate invoices')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'Invoice Id'
            )
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address to send to'
            )
            ->addOption(
                'regenerate',
                null,
                InputOption::VALUE_NONE,
                'regenerate invoice'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        $regenerate = true === $input->getOption('regenerate');
        $email = $input->getOption('email');
        if ($id) {
            /** @var InvoiceService $invoiceService */
            $invoiceService = $this->getContainer()->get('app.invoice');
            $invoice = $this->getInvoice($id);
            if (!$invoice) {
                throw new \Exception(sprintf('Unable to find invoice %s', $id));
            }
            $result = $invoiceService->generateInvoice($invoice, $email, $regenerate);
            $output->writeln(sprintf('Tmp Invoice File: %s', $result['file']));
        }
    }

    private function getInvoice($id)
    {
        $repo = $this->dm->getRepository(Invoice::class);

        return $repo->find($id);
    }
}
