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

class DaviesBreakdownCommand extends ContainerAwareCommand
{
    use DateTrait;
    use CurrencyTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:davies:breakdown')
            ->setDescription('Email policy breakdown report to davies')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $emailAddresses = ['patrick@so-sure.com','laura.harvey@davies-group.com','dylan@so-sure.com'];
        $now = new \DateTime();
        $filename = sprintf("so-sure-policy-breakdown-%s.pdf", $now->format('Y-m-d'));
        $tmpFile = sprintf(
            "%s/%s",
            sys_get_temp_dir(),
            $filename
        );
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $policyService = $this->getContainer()->get('app.policy');
        $policyService->getBreakdownPdf($tmpFile);

        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            sprintf('so-sure Policy Breakdown Report'),
            $emailAddresses,
            'AppBundle:Email:davies/breakdown.html.twig',
            [],
            null,
            null,
            [$tmpFile]
        );

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $output->writeln('Policy Breakdown Report sent.');
    }
}
