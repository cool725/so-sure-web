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

class DaviesInvoiceCommand extends ContainerAwareCommand
{
    use CurrencyTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:davies:invoice')
            ->setDescription('Generate a davies invoice for claimscheck')
            ->addArgument(
                'date',
                InputArgument::REQUIRED,
                'date'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $date = new \DateTime($input->getArgument('date'));

        $dm = $this->getManager();
        $charges = $this->getCharges($date);
        if (count($charges) > 0) {
            $invoice = Invoice::generateDaviesInvoice();
            $data = [];
            foreach ($charges as $charge) {
                // multiply by 100 as float's don't work as array keys
                $amount = $this->toTwoDp($charge->getAmount()) * 100;
                if (!isset($data[$amount])) {
                    $data[$amount] = 0;
                }
                $data[$amount]++;
                $charge->setInvoice($invoice);
            }
            foreach ($data as $amount => $quantity) {
                // divide by 100 as was previously incremented to work for array key
                // add vat
                $actualAmount = $this->toTwoDp($amount * (1 + $this->getCurrentVatRate()) / 100);
                $item = new InvoiceItem($actualAmount, $quantity);
                $item->setDescription(sprintf('ClaimsCheck for %s', $date->format('M Y')));
                $invoice->addInvoiceItem($item);
            }
            $dm->persist($invoice);
            $dm->flush();

            $invoiceService = $this->getContainer()->get('app.invoice');
            $invoiceService->generateInvoice($invoice, 'patrick@so-sure.com');
            $output->writeln(sprintf('Invoice %s generated and emailed', $invoice->getInvoiceNumber()));
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

        return $repo->findMonthly($date, Charge::TYPE_CLAIMSCHECK, true);
    }
}
