<?php

namespace AppBundle\Command;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
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

class BacsPdfCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var BacsService  */
    protected $bacsService;

    public function __construct(BacsService $bacsService)
    {
        parent::__construct();
        $this->bacsService = $bacsService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:bacs:pdf')
            ->setDescription('Process bacs pdf')
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Clear the queue (WARNING!!)'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process',
                50
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show items in the queue'
            )
            ->addOption(
                'requeue',
                null,
                InputOption::VALUE_REQUIRED,
                'Policy id to requeue'
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
        $clear = true === $input->getOption('clear');
        $show = true === $input->getOption('show');
        $process = $input->getOption('process');
        $requeue = $input->getOption('requeue');

        if ($clear) {
            $this->bacsService->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($show) {
            $data = $this->bacsService->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } elseif ($requeue) {
            $this->bacsService->queueBacsCreated($this->bacsService->getPolicy($requeue));
            $output->writeln(sprintf("Requeued policy for bacs pdf"));
        } else {
            $count = $this->bacsService->process($process);
            $output->writeln(sprintf("Processed %s bacs instructions", $count));
        }

        $output->writeln('Finished');
    }
}
