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
    /** @var BacsService  */
    protected $bacsService;

    /** @var MailerService */
    protected $mailerService;

    /**
     * @param BacsService   $bacsService   allows interaction with the bacs system.
     * @param MailerService $mailerService allows sending emails.
     */
    public function __construct(BacsService $bacsService, MailerService $mailerService)
    {
        parent::__construct();
        $this->bacsService = $bacsService;
        $this->mailerService = $mailerService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:bacs:report')->setDescription('Import bacs reports');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $result = static::processBacs($this->bacsService, $output, $this->mailerService);
        $output->writeln($result);
    }

    /**
     * Processes the bacs reports currently in the sftp if it can get the lock.
     * @param BacsService     $bacsService is the bacs service.
     * @param OutputInterface $output      is used to provide info.
     * @param MailerService   $mailer      sends an email on completion.
     */
    private static function processBacs($bacsService, $output, $mailer)
    {
        $results = $bacsService->sftp();
        if (count($results) > 0) {
            $data = json_encode($results, JSON_PRETTY_PRINT);
            $output->writeln($data);
            $mailer->send(
                'Bacs Report Input',
                'tech+ops@so-sure.com',
                sprintf('Bacs Report Input Results:<br /> %s', nl2br($data))
            );
        } else {
            $output->writeln('Nothing to process');
        }
    }
}
