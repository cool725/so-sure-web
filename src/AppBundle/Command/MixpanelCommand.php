<?php

namespace AppBundle\Command;

use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\MixpanelService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;

class MixpanelCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    /** @var MixpanelService */
    protected $mixpanelService;

    /** @var Client  */
    protected $redis;

    public function __construct(DocumentManager $dm, MixpanelService $mixpanelService, Client $redis)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->mixpanelService = $mixpanelService;
        $this->redis = $redis;
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
                'delete|delete-old-users|test|clear|show|sync|sync-all|data|attribution|freeze-attribution|attribution-duplicate-users|count-users|count-queue|reattribute-recent|extend-cache (or blank for process)'
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
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'Type of mixpanel queue item to clear'
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
        $type = $input->getOption('type');
        $user = null;
        if ($email) {
            if ($user = $this->getUser($email)) {
                $id = $user->getId();
            }
        }
        if ($action == 'attribution-duplicate-users') {
            $duplicateUsers = $this->mixpanelService->findDuplicateUsers();
            foreach ($duplicateUsers as $user) {
                $this->mixpanelService->queueAttribution($user, true);
            }
            $output->writeln(sprintf('Queued update for all '.count($duplicateUsers).' users duplicated on mixpanel.'));
        } elseif ($action == 'data') {
            $end = \DateTime::createFromFormat('U', time());
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
            $output->writeln(sprintf(
                "Queued %d users for deletion (of %d total users)",
                $data['count'],
                $data['total']
            ));
        } elseif ($action == 'count-users') {
            $total = $this->mixpanelService->getUserCount();
            $output->writeln(sprintf("%d Users", $total));
        } elseif ($action == 'count-queue') {
            $total = $this->mixpanelService->countQueue();
            $output->writeln(sprintf("%d in queue", $total));
        } elseif ($action == 'clear') {
            if ($type) {
                if (in_array($type, MixpanelService::QUEUE_TYPES)) {
                    $count = $this->mixpanelService->clearQueuedType($type);
                    $output->writeln("Removed {$count} queued {$type} items.");
                } else {
                    $output->writeln(sprintf("{$type} is not a valid queue item type."));
                }
            } else {
                $this->mixpanelService->clearQueue();
                $output->writeln(sprintf("Queue is cleared"));
            }
        } elseif ($action == 'show') {
            $data = $this->mixpanelService->getQueueData($process);
            $output->writeln(sprintf("Queue Size: %d", count($data)));
            foreach ($data as $line) {
                $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
            }
        } elseif ($action == 'freeze-attribution') {
            $n = $this->freezeAttributions($days);
            $output->writeln("Queued {$n} users to have blank attribution set.");
        } elseif ($action == 'reattribute-recent') {
            if (!$days) {
                throw new \Exception('Requires time period; add --days');
            }
            $date = new \DateTime();
            $startDate = $this->subDays($date, $days);
            /** @var UserRepository */
            $userRepo = $this->dm->getRepository(User::class);
            $users = $userRepo->findNewUsers($startDate, $date);
            foreach ($users as $user) {
                $this->mixpanelService->queueAttribution($user, true);
            }
        } elseif ($action == 'extend-cache') {
            $cachedItems = $this->redis->keys("mixpanel:user:*");
            array_merge($cachedItems, $this->redis->keys("mixpanel:oldest:*"));
            foreach ($cachedItems as $item) {
                $this->redis->setex($item, MixpanelService::CACHE_TIME, $this->redis->get($item));
            }
        } else {
            $data = $this->mixpanelService->process($process);
            $output->writeln(sprintf("Processed %d Requeued: %d", $data['processed'], $data['requeued']));
        }
    }

    /**
     * Finds all the unattributed users who are more than a certain age old and queues them to get an empty attribution.
     * @param int $days is the number of days old they must be to get an empty attribution.
     * @return int the number of users who just got queued.
     */
    private function freezeAttributions($days)
    {
        $end = new \DateTime();
        $end->sub(new \DateInterval("P{$days}D"));
        $userRepository = $this->getUserRepository();
        $users = $userRepository->findUnattributedUsers(null, $end);
        foreach ($users as $user) {
            $this->mixpanelService->queueFreezeAttribution($user);
        }
        return count($users);
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
