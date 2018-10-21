<?php

namespace AppBundle\Command;

use AppBundle\Service\PushService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;

class PushCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var PushService  */
    protected $pushService;

    public function __construct(DocumentManager $dm, PushService $pushService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->pushService = $pushService;
    }

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
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'message type (general, connected)',
                'general'
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
        $messageType = $input->getOption('type');
        $arn = $input->getOption('arn');
        $email = $input->getOption('email');

        // @codingStandardsIgnoreStart
        // 'arn:aws:sns:eu-west-1:812402538357:endpoint/APNS_SANDBOX/so-sure_ios_dev/86a504df-8470-3c9e-a60e-7611df452f08',
        // @codingStandardsIgnoreEnd

        if (mb_strlen($email) > 0) {
            $user = $this->getUser($email);
            if (!$user) {
                throw new \Exception('Unable to find user');
            }
            if (mb_strlen($user->getSnsEndpoint()) == 0) {
                throw new \Exception('User does not have a sns endpoint registered');
            }
            $this->pushService->sendToUser($messageType, $user, $message);
            $output->writeln('Sent message');
        } elseif (mb_strlen($arn) > 0) {
            $this->pushService->send($messageType, $arn, $message);
            $output->writeln('Sent message');
        } else {
            $output->writeln('Nothing to do - use --email or --arn');
        }
    }

    private function getUser($email)
    {
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => $email]);

        return $user;
    }
}
