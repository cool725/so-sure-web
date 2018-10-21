<?php

namespace AppBundle\Command;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\SoSure;

class CacheCommand extends ContainerAwareCommand
{
    /** @var Client  */
    protected $redis;

    public function __construct(Client $redis)
    {
        parent::__construct();
        $this->redis = $redis;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:cache')
            ->setDescription('Cache commands')
            ->addOption(
                'prefix',
                null,
                InputOption::VALUE_REQUIRED,
                'Redis key prefix to clear'
            )
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'list|clear'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $prefix = $input->getOption('prefix');
        if (!in_array($action, ['list', 'clear'])) {
            throw new \Exception('Unknown action');
        }
        if (mb_strlen($prefix) < 2) {
            throw new \Exception('Prefix must be at least 2 chars. Use redis:flushdb to clear database');
        }

        foreach ($this->redis->keys(sprintf('%s*', $prefix)) as $key) {
            if ($action == 'clear') {
                $output->writeln(sprintf('Clearing %s', $key));
                $this->redis->del($key);
            } elseif ($action == 'list') {
                $output->writeln(sprintf('%s', $key));
            }
        }

        $output->writeln('Finished');
    }
}
