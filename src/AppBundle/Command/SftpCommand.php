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
use AppBundle\Service\SftpService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\User;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

class SftpCommand extends ContainerAwareCommand
{
    use DateTrait;
    const S3_BUCKET = 'admin.so-sure.com';

    /** @var DocumentManager  */
    protected $dm;

    /** @var SftpService  */
    protected $sftpService;

    public function __construct(
        DocumentManager $dm,
        SftpService $sosureSftpService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->sftpService = $sosureSftpService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:sftp')
            ->setDescription('sftp test')
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
        $data = $this->sftpService->listSftp();
        print_r($data);
        $output->writeln('Finished');
    }
}
