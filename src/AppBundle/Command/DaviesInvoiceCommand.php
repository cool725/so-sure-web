<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Charge;
use AppBundle\Document\Invoice;
use AppBundle\Document\InvoiceItem;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class DaviesInvoiceCommand extends ContainerAwareCommand
{
    use DateTrait;
    use CurrencyTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:davies:invoice')
            ->setDescription('Generate a davies invoice for claimscheck')
            ->addOption(
                'date',
                null,
                InputOption::VALUE_REQUIRED,
                'date'
            )
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'if set, email accounts.payable@davies-group.com'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = $input->getOption('date');
        if ($date) {
            $date = new \DateTime($input->getOption('date'));
        } else {
            $date = $this->startOfPreviousMonth();
            $output->writeln(sprintf('Using last month %s', $date->format('Y-m')));
        }

        $skipEmail = true === $input->getOption('skip-email');
        $emailAddress = [
            'accounts.payable@davies-group.com',
            'Debbie.Moores@davies-group.com',
            'patrick@so-sure.com',
            'dylan@so-sure.com'
        ];
        if ($skipEmail) {
            $emailAddress = null;
        }

        $dm = $this->getManager();
        $charges = $this->getCharges($date);
        if (count($charges) > 0) {
            $invoice = Invoice::generateDaviesInvoice();
            $data = [];
            foreach ($charges as $charge) {
                $charge->setInvoice($invoice);
                $item = new InvoiceItem($charge->getAmountWithVat(), 1);
                $item->setDescription($charge->__toString());
                $invoice->addInvoiceItem($item);
            }
            $dm->persist($invoice);
            $dm->flush();

            $invoiceService = $this->getContainer()->get('app.invoice');
            $invoiceService->generateInvoice($invoice, $emailAddress);
            if ($emailAddress) {
                $output->writeln(sprintf('Invoice %s generated and emailed', $invoice->getInvoiceNumber()));
            } else {
                $output->writeln(sprintf('Invoice %s generated', $invoice->getInvoiceNumber()));
            }
        } else {
            $output->writeln(sprintf('No charges for %s', $date->format('M Y')));
        }

        // if claimscheck charges
        // create invoice
        // add all claimcheck items to invoice (invoiceitems + set charge invoice)
        // generate invoice + email
        $output->writeln('Finished');
    }

    private function getManager()
    {
        return $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    private function getCharges($date)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Charge::class);

        return $repo->findMonthly($date, [Charge::TYPE_CLAIMSCHECK, Charge::TYPE_CLAIMSDAMAGE], true);
    }
}
