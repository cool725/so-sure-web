<?php

namespace AppBundle\Command;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Document\Charge;
use AppBundle\Document\Invoice;
use AppBundle\Document\InvoiceItem;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class PolicyBreakdownCommand extends ContainerAwareCommand
{
    use DateTrait;
    use CurrencyTrait;

    /** @var PolicyService */
    protected $policyService;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(PolicyService $policyService, MailerService $mailerService)
    {
        parent::__construct();
        $this->policyService = $policyService;
        $this->mailerService = $mailerService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:policy:breakdown')
            ->setDescription('Email policy breakdown report to claims handlers')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $now = \DateTime::createFromFormat('U', time());
        $filename = sprintf("so-sure-policy-breakdown-%s.pdf", $now->format('Y-m-d'));
        $tmpFile = sprintf(
            "%s/%s",
            sys_get_temp_dir(),
            $filename
        );
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $this->policyService->getBreakdownPdf($tmpFile);

        $this->mailerService->sendTemplate(
            sprintf('so-sure Policy Breakdown Report'),
            ['julien@so-sure.com','dylan@so-sure.com'],
            'AppBundle:Email:claimsHandler/breakdown.html.twig',
            [],
            null,
            null,
            [$tmpFile],
            array_merge(DaviesHandlerClaim::$breakdownEmailAddresses, DirectGroupHandlerClaim::$breakdownEmailAddresses)
        );

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $output->writeln('Policy Breakdown Report sent.');
    }
}
