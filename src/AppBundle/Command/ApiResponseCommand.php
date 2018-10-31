<?php

namespace AppBundle\Command;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Listener\ApiResponseSubscriber;

class ApiResponseCommand extends ContainerAwareCommand
{
    /** @var Client */
    protected $redis;

    public function __construct(Client $redis)
    {
        parent::__construct();
        $this->redis = $redis;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:apiresponse')
            ->setDescription('Return known error for testing')
            ->addOption(
                'random',
                null,
                InputOption::VALUE_REQUIRED,
                'Random % to fail with 500'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Url path (start with /api/v1/)'
            )
            ->addOption(
                'path-error',
                null,
                InputOption::VALUE_OPTIONAL,
                'If used with path, error code to use',
                500
            )
            ->addOption(
                'clear',
                null,
                InputOption::VALUE_NONE,
                'Clear all responses'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $path = $input->getOption('path');
        $pathError = $input->getOption('path-error');
        $clear = true === $input->getOption('clear');
        $random = $input->getOption('random');

        if ($random) {
            $this->redis->set(ApiResponseSubscriber::KEY_RANDOM_FAILURE, $random);
        }
        if ($path) {
            $this->redis->hset(ApiResponseSubscriber::KEY_HASH_PATH, $path, $pathError);
        }
        
        if ($clear) {
            $this->redis->del([ApiResponseSubscriber::KEY_RANDOM_FAILURE]);
            $this->redis->del([ApiResponseSubscriber::KEY_HASH_PATH]);
            $output->writeln('Cleared');
        } else {
            $output->writeln(sprintf(
                "Response %s%%",
                $this->redis->get(ApiResponseSubscriber::KEY_RANDOM_FAILURE)
            ));
            $output->writeln(json_encode($this->redis->hgetall(ApiResponseSubscriber::KEY_HASH_PATH)));
        }
    }
}
