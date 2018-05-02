<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use AppBundle\Document\User;

class ExceptionCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:exception')
            ->setDescription('Throw exception to test cli return code')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var mixed $user */
        $user = new User();
        $user->email;
        // new \Exception('Test Exception');
        // throw new FatalThrowableError(new \Exception('Test Exception'));
    }
}
