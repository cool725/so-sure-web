<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use AppBundle\Repository\UserRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\MailerService;
use AppBundle\Service\PolicyService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;

class UsersEmailCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var MailerService */
    protected $mailerService;

    public function __construct(DocumentManager $dm, MailerService $mailerService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->mailerService = $mailerService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:users:email')
            ->setDescription('Email all users')
            ->addOption(
                'skip-email',
                null,
                InputOption::VALUE_NONE,
                'if set, do not email'
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'optional id to restart from'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $skipEmail = true === $input->getOption('skip-email');
        $from = $input->getOption('from');
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findAll();
        $output->writeln(sprintf('%d users', count($users)));
        $process = true;
        if ($from) {
            $process = false;
        }
        foreach ($users as $user) {
            /** @var User $user */
            if ($from && $user->getId() == $from) {
                $process = true;
            }

            if (!$process) {
                continue;
            }

            try {
                if (!$skipEmail) {
                    $this->emailUser($user);
                }
                $output->writeln($user->getId());
            } catch (\Exception $e) {
                $output->writeln(sprintf('Error %s %s', $user->getId(), $e->getMessage()));
            }
        }

        $output->writeln('Finished');
    }

    private function emailUser(User $user)
    {
        $hash = SoSure::encodeCommunicationsHash($user->getEmail());
        $this->mailerService->sendTemplateToUser(
            'Updated Privacy Policy',
            $user,
            'AppBundle:Email:user/updatedPrivacyPolicy.html.twig',
            ['user' => $user, 'hash' => $hash],
            'AppBundle:Email:user/updatedPrivacyPolicy.txt.twig',
            ['user' => $user, 'hash' => $hash]
        );


    }
}
