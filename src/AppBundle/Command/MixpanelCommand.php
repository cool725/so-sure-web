<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\MixpanelService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;

class MixpanelCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    /** @var MixpanelService */
    protected $mixpanelService;

    public function __construct(DocumentManager $dm, MixpanelService $mixpanelService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->mixpanelService = $mixpanelService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:mixpanel')
            ->setDescription('Run mixpanel')
            // @codingStandardsIgnoreStart
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'delete|delete-old-users|test|clear|show|sync|sync-all|data|attribution|count-users|count-queue (or blank for process)'
            )
            // @codingStandardsIgnoreEnd
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'email address of user'
            )
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'id of mixpanel user'
            )
            ->addOption(
                'process',
                null,
                InputOption::VALUE_REQUIRED,
                'Max Number to process',
                50
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of days to keep users',
                90
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $action = $input->getArgument('action');
        $email = $input->getOption('email');
        $id = $input->getOption('id');
        $process = $input->getOption('process');
        $days = $input->getOption('days');
        $user = null;
        if ($email) {
            if ($user = $this->getUser($email)) {
                $id = $user->getId();
            }
        }
        if ($action == 'test') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $this->mixpanelService->queueTrackWithUser($user, "button clicked", array("label" => "sign-up"));
            $output->writeln(sprintf('Queued test event'));
        } elseif ($action == 'data') {
            $end = new \DateTime();
            $end->sub(new \DateInterval(sprintf('P%dD', $end->format('N'))));
            $start = clone $end;
            $start->sub(new \DateInterval('P6D'));
            $output->writeln(sprintf('Running from %s to %s', $start->format('Y-m-d'), $end->format('Y-m-d')));
            $results = $this->mixpanelService->stats($start, $end);
            print_r($results);
            $output->writeln('Finished');
        } elseif ($action == 'attribution') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $results = $this->mixpanelService->attributionByUser($user);
            $output->writeln(sprintf('Attribution %s', json_encode($results, JSON_PRETTY_PRINT)));
        } elseif ($action == 'sync') {
            if (!$user) {
                throw new \Exception('Requires user; add --email');
            }
            $this->mixpanelService->updateUser($user);
            $output->writeln('Queued user update');
        } elseif ($action == 'sync-all') {
            $policies = $this->getPhonePolicyRepository()->findAllActiveUnpaidPolicies();
            $count = 0;
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                $this->mixpanelService->updateUser($policy->getUser());
                $count++;
            }
            $output->writeln(sprintf('Queued %d user update', $count));
        } elseif ($action == 'delete') {
            if (!$id) {
                throw new \Exception('email or id is required');
            }
            $this->mixpanelService->queueDelete($id);
            $output->writeln(sprintf('Queued user delete'));
        } elseif ($action == 'delete-old-users') {
            $data = $this->mixpanelService->deleteOldUsers($days);
            $output->writeln(sprintf("Queued %d users for deletion (of %d)", $data['count'], $data['total']));
        } elseif ($action == 'count-users') {
            $total = $this->mixpanelService->getUserCount();
            $output->writeln(sprintf("%d Users", $total));
        } elseif ($action == 'count-queue') {
            $total = $this->mixpanelService->countQueue();
            $output->writeln(sprintf("%d in queue", $total));
        } elseif ($action == 'clear') {
            $this->mixpanelService->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
        } elseif ($action == 'show') {
            $data = $this->mixpanelService->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } else {
            $data = $this->mixpanelService->process($process);
            $output->writeln(sprintf("Processed %d Requeued: %d", $data['processed'], $data['requeued']));
        }
    }

    /**
     * @param string $email
     * @return User
     * @throws \Exception
     */
    private function getUser($email)
    {
        $repo = $this->getUserRepository();
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if (!$user) {
            throw new \Exception('unable to find user');
        }

        return $user;
    }

    /**
     * @return UserRepository
     */
    private function getUserRepository()
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);

        return $repo;
    }

    /**
     * @return PhonePolicyRepository
     */
    private function getPhonePolicyRepository()
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);

        return $repo;
    }
}
