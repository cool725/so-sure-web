<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
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
    use DateTrait;
    const S3_BUCKET = 'admin.so-sure.com';

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
        $this
            ->setName('sosure:bacs:report')
            ->setDescription('Import bacs reports')
        ;
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|null|void
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
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
