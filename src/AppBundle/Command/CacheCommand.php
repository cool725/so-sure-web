<?php

namespace AppBundle\Command;

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

class CacheCommand extends BaseCommand
{
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
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $prefix = $input->getOption('prefix');
        $redis = $this->getContainer()->get('snc_redis.default');
        foreach ($redis->keys(sprintf('%s*', $prefix)) as $key) {
            $output->writeln(sprintf('Clearing %s', $key));
            $redis->del($key);
        }

        $output->writeln('Finished');
    }
}
