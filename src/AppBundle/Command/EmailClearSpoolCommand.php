<?php

namespace AppBundle\Command;

use AppBundle\Service\PCAService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class EmailClearSpoolCommand extends ContainerAwareCommand
{
    /** @var Client  */
    protected $redisMailer;

    public function __construct(Client $redisMailer)
    {
        parent::__construct();
        $this->redisMailer = $redisMailer;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:email:clear-spool')
            ->setDescription('Delete email spool')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln(sprintf(
            '%d emails in queue (prior to this)',
            $this->redisMailer->llen('swiftmailer')
        ));

        $this->redisMailer->del(['swiftmailer']);
    }
}
