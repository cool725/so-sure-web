<?php

namespace App\Command;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Exception\QueueException;
use AppBundle\Service\HubspotService;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Doctrine\ODM\MongoDB\DocumentManager;
use SevenShores\Hubspot\Exceptions\BadRequest;

/**
 * Allows control of the hubspot system via commandline.
 */
class HubspotCommand extends ContainerAwareCommand
{
    private const COMMAND_NAME = "sosure:hubspot";
    protected static $defaultName = self::COMMAND_NAME;

    const QUEUE_RATE_DEFAULT = 15;
    const QUEUE_MAX_SHOW = 100;

    const PROPERTY_NAME = "sosure";
    const PROPERTY_DESC = "Custom properties used by SoSure";
    const LIST_NAME = "SoSure Customers";

    /** @var HubspotService */
    private $hubspot;
    /** @var LoggerInterface */
    private $logger;
    /** @var DocumentManager */
    private $dm;
    private $environment;

    /**
     * Builds the command object.
     * @param HubspotService  $hubspot     is the hubspot service which this command drives.
     * @param DocumentManager $dm          document manager used for accessing repository data.
     * @param string          $environment is the environme4nt that this is running in.
     */
    public function __construct(HubspotService $hubspot, DocumentManager $dm, $environment)
    {
        parent::__construct();
        $this->hubspot = $hubspot;
        $this->dm = $dm;
        $this->environment = $environment;
    }

    /**
     * Defines it's commandline arguments.
     */
    protected function configure()
    {
        $this->setDescription("Controls the Hubspot service which syncs data to a hubspot")
            ->addArgument(
                "action",
                InputArgument::REQUIRED,
                // @codingStandardsIgnoreStart
                "sync-all|sync-user|sync-user-property|sync-policy-property|drop|queue-count|queue-show|queue-clear|test|process"
                // @codingStandardsIgnoreEnd
            )
            ->addOption(
                "email",
                null,
                InputOption::VALUE_OPTIONAL,
                "email of user to sync if <info>sync-user</info> is run."
            )
            ->addOption(
                "n",
                null,
                InputOption::VALUE_OPTIONAL,
                "Number of messages to process if <info>process</info> is run.",
                self::QUEUE_RATE_DEFAULT
            )
            ->addOption(
                "p",
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                "Properties to be synced by <info>sync-user-property</info> or <info>sync-policy-property</info>."
            );
    }

    /**
     * Entrypoint of command execution.
     * @param InputInterface  $input  gives access to commandline input.
     * @param OutputInterface $output gives access to write to commandline.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        try {
            $action = $input->getArgument("action");
            $email = $input->getOption("email");
            $process = (int)$input->getOption("n");
            $properties = $input->getOption("p");
            switch ($action) {
                case "sync-all":
                    $this->syncAllUsers($output);
                    break;
                case "sync-user-property":
                    $this->syncUserProperty($output, $properties);
                    break;
                case "sync-policy-property":
                    $this->syncPolicyProperty($output, $properties);
                    break;
                case "sync-user":
                    /** @var User $user */
                    $user = $userRepo->findOneBy(["email" => $email]);
                    if ($user) {
                        $this->hubspot->createOrUpdateContact($user);
                    } else {
                        $output->writeln("<info>{$email}</info> does not belong to a user.");
                    }
                    break;
                case "drop":
                    $this->drop($input, $output);
                    break;
                case "queue-count":
                    $this->queueCount($output);
                    break;
                case "queue-show":
                    $this->queueShow(self::QUEUE_MAX_SHOW, $output);
                    break;
                case "queue-clear":
                    $this->queueClear($input, $output);
                    break;
                case "test":
                    $this->test($output);
                    break;
                case "process":
                    $this->process($output, $process);
                    break;
                default:
                    throw new QueueException("'{$action}' is not a valid action.");
            }
        } catch (QueueException $e) {
            $output->writeln("<error>".$e->getMessage()."</error>\n");
        } catch (\Exception $e) {
            $output->writeln(
                "<info>An error occurred:</info>\n<error>".$e->getMessage()."</error>\n".get_class($e)
            );
        }
    }

    /**
     * Gives the length of the queue.
     * @param OutputInterface $output is used to output the number.
     */
    private function queueCount(OutputInterface $output)
    {
        $count = $this->hubspot->countQueue();
        $output->writeln(sprintf("%d in queue", $count));
    }

    /**
     * Displays the queue.
     * @param int             $max    is the maximum number of queue items to show.
     * @param OutputInterface $output is used to output to the commandline.
     */
    private function queueShow($max, OutputInterface $output)
    {
        $data = $this->hubspot->getQueueData($max);
        $output->writeln(sprintf("Queue Size: %d", count($data)));
        foreach ($data as $line) {
            $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
        }
    }

    /**
     * Deletes everything in the queue.
     * @param InputInterface  $input  used to take user confirmation from the commandline.
     * @param OutputInterface $output used to output to the commandline.
     */
    private function queueClear(InputInterface $input, OutputInterface $output)
    {
        $this->hubspot->clearQueue();
        $output->writeln(sprintf("Queue is cleared"));
    }

    /**
     * Puts every single user in the database onto the queue.
     * @param OutputInterface $output is used to output to the commandline.
     */
    private function syncAllUsers(OutputInterface $output)
    {
        $count = 0;
        $users = $this->dm->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            $this->hubspot->queueUpdateContact($user);
            $count++;
        }
        $output->writeln(sprintf("Queued %d Users", $count));
    }

    /**
     * Synchronously uploads a given property for every hubspot user to hubspot.
     * @param OutputInterface $output     is output for showing some info about what we are doing.
     * @param array           $properties is the list of properties to sync. It can not be empty.
     */
    private function syncUserProperty(OutputInterface $output, $properties)
    {
        if (!$properties) {
            $output->writeln("Property not given.");
            return;
        }
        /** @var UserRepository */
        $userRepo = $this->dm->getRepository(User::class);
        $groups = $userRepo->findHubspotUsersGrouped();
        $progressBar = new ProgressBar($output);
        $started = false;
        foreach ($groups as $group) {
            if (!$started) {
                $progressBar->start($group->count());
                $started = true;
            }
            $this->hubspot->updateContactBatch($group, $properties);
            $progressBar->advance($group->count(true));
        }
        $progressBar->finish();
        $output->writeln("");
    }

    /**
     * Synchronously uploads a given property for every hubspot policy to hubspot.
     * @param OutputInterface $output     is output for showing some info about what we are doing.
     * @param array           $properties is the list of properties to sync. It can not be empty.
     */
    private function syncPolicyProperty(OutputInterface $output, $properties)
    {
        if (!$properties) {
            $output->writeln("Property not given.");
            return;
        }
        /** @var PolicyRepository */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $groups = $policyRepo->findHubspotPoliciesGrouped();
        $progressBar = new ProgressBar($output);
        $started = false;
        foreach ($groups as $group) {
            if (!$started) {
                $progressBar->start($group->count());
                $started = true;
            }
            $this->hubspot->updateDealBatch($group, $properties);
            $progressBar->advance($group->count(true));
        }
        $progressBar->finish();
        $output->writeln("");
    }

    /**
     * Deletes all customers and associated deals on hubspot.
     * @param InputInterface  $input  is used to take user confirmation.
     * @param OutputInterface $output is used for reporting some output.
     */
    private function drop($input, $output)
    {
        // make sure that we are good to do this.
        if ($this->environment == "prod") {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("This is PROD. Definitely remove all users from Hubspot?", false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("Aborting");
                return;
            }
        }
        // begin.
        $dealCount = 0;
        $contactCount = 0;
        // Delete policies
        $output->writeln("Deleting Deals:");
        $progressBar = new ProgressBar($output);
        foreach ($this->hubspot->getAllDeals() as $deal) {
            $this->hubspot->deleteDeal($deal);
            $progressBar->advance();
            $dealCount++;
        }
        $progressBar->finish();
        $output->writeln("");
        // Delete users.
        $output->writeln("Deleting Contacts:");
        $progressBar = new ProgressBar($output);
        foreach ($this->hubspot->getAllContacts() as $contact) {
            $this->hubspot->deleteContact($contact);
            $progressBar->advance();
            $contactCount++;
        }
        $progressBar->finish();
        $output->writeln("");
        // Delete all hubspot ids out of our system since all hubspot contacts and deals were just deleted.
        /** @var UserRepository */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var PolicyRepository */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $userRepo->removeHubspotIds();
        $policyRepo->removeHubspotIds();
        $this->dm->flush();
        // Final output.
        $output->writeln("Dropped <info>{$dealCount}</info> deals.");
        $output->writeln("Dropped <info>{$contactCount}</info> contacts.");
    }

    /**
     * Temporary or testing functionality.
     * @param OutputInterface $output allows output to the commandline.
     */
    private function test($output)
    {
        $output->writeln("testing...");
    }

    /**
     * Processes items in the queue.
     * @param OutputInterface $output is used to log to the commandline.
     * @param int             $max    is the maximum number of queue items to process.
     */
    public function process(OutputInterface $output, $max)
    {
        $counts = $this->hubspot->process($max, $output);
        $output->writeln(sprintf(
            'Sent %s updates, %s requeued, %s dropped',
            $counts["processed"],
            $counts["requeued"],
            $counts["dropped"]
        ));
    }
}
