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

    /** @var DocumentManager  */
    protected $dm;

    /** @var SftpService */
    protected $sosureSftpService;

    /** @var SftpService */
    protected $accesspaySftpService;

    /** @var SftpService */
    protected $directgroupSftpService;

    public function __construct(
        DocumentManager $dm,
        SftpService $sosureSftpService,
        SftpService $accesspaySftpService,
        SftpService $directgroupSftpService
    ) {
        parent::__construct();
        $this->dm = $dm;
        $this->sosureSftpService = $sosureSftpService;
        $this->accesspaySftpService = $accesspaySftpService;
        $this->directgroupSftpService = $directgroupSftpService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:sftp')
            ->setDescription('sftp list files')
            ->addArgument(
                'server',
                InputArgument::REQUIRED,
                'sosure, accesspay, directgroup'
            )
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
        $server = $input->getArgument('server');
        $data = null;
        if ($server == 'sosure') {
            $data = $this->sosureSftpService->listSftp();
        } elseif ($server == 'accesspay') {
            $data = $this->accesspaySftpService->listSftp();
        } elseif ($server == 'directgroup') {
            $data = $this->directgroupSftpService->listSftp();
        }
        $output->writeln(json_encode($data));
        $output->writeln('Finished');
    }
}
