<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class PushCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:push')
            ->setDescription('Send a sns notification')
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address of user'
            )
            ->addOption(
                'arn',
                null,
                InputOption::VALUE_REQUIRED,
                'arn to send to'
            )
            ->addArgument(
                'message',
                InputArgument::REQUIRED,
                'Message to send'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $message = $input->getArgument('message');
        $arn = $input->getOption('arn');
        $email = $input->getOption('email');

        $push = $this->getContainer()->get('app.push');
        // @codingStandardsIgnoreStart
        // 'arn:aws:sns:eu-west-1:812402538357:endpoint/APNS_SANDBOX/so-sure_ios_dev/86a504df-8470-3c9e-a60e-7611df452f08',
        // @codingStandardsIgnoreEnd

        if (strlen($email) > 0) {
            $user = $this->getUser($email);
            if (!$user) {
                throw new \Exception('Unable to find user');
            }
            if (strlen($user->getSnsEndpoint()) == 0) {
                throw new \Exception('User does not have a sns endpoint registered');
            }
            $push->sendToUser($user, $message);
            $output->writeln('Sent message');
        } elseif (strlen($arn) > 0) {
            $push->send($arn, $message);
            $output->writeln('Sent message');
        } else {
            $output->writeln('Nothing to do - use --email or --arn');
        }
    }

    private function getUser($email)
    {
        $dm = $this->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);

        return $repo->findOneBy(['emailCanonical' => $email]);
    }
}
