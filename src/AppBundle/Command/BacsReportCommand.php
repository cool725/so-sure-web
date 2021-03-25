<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\SequenceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

class BacsReportCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var BacsService  */
    protected $bacsService;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(
        DocumentManager $dm,
        BacsService $bacsService,
        MailerService $mailerService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->bacsService = $bacsService;
        $this->mailerService = $mailerService;
    }

    protected function configure()
    {
        $this->setName('sosure:bacs:report')
            ->setDescription('Import bacs reports')
            ->addOption('clear-flag', null, InputOption::VALUE_NONE, 'clear the processing flag and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $clear = $input->getOption('clear-flag');
        if ($clear) {
            $this->bacsService->clearSftpRunning();
            $output->writeln('cleared');
        } else {
            $results = $this->bacsService->sftp();
            if (count($results) > 0) {
                $data = json_encode($results, JSON_PRETTY_PRINT);
                $output->writeln($data);
                $this->mailerService->send(
                    'Bacs Report Input',
                    'tech+ops@so-sure.com',
                    sprintf('Bacs Report Input Results:<br /> %s', nl2br($data))
                );
            } else {
                $output->writeln('Nothing to process');
            }
            $output->writeln('Finished');
        }
    }
}
