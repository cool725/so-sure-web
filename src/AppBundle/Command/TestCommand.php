<?php

namespace AppBundle\Command;

use AppBundle\Document\User;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;

class TestCommand extends BaseCommand
{
    use DateTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:test')
            ->setDescription('Test')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->getManager()->getRepository(User::class);
        foreach ($repo->findAll() as $user) {
            /** @var User $user */
            if ($user->getBirthday()) {
                if (($user->getBirthday()->format('H') == 0 && $user->getBirthday()->format('P') == "+01:00") ||
                    $user->getBirthday()->format('H') == 23 && $user->getBirthday()->format('P') == "+00:00") {
                    print sprintf("%s %s%s", $user->getId(), $user->getBirthday()->format(\DateTime::ATOM), PHP_EOL);
                }
            }
        }


        $output->writeln('Finished');
    }
}
