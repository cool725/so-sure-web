<?php

namespace App\Command;

use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
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

    const QUEUE_RATE_DEFAULT = 50;
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

    /**
     * Builds the command object.
     * @param HubspotService  $hubspot is the hubspot service which this command drives.
     * @param DocumentManager $dm      document manager used for accessing repository data.
     */
    public function __construct(HubspotService $hubspot, DocumentManager $dm)
    {
        parent::__construct();
        $this->hubspot = $hubspot;
        $this->dm = $dm;
    }

    /**
     * Defines it's commandline arguments.
     */
    protected function configure()
    {
        $this->setDescription("Sync data with HubSpot")
            ->addArgument(
                "action",
                InputArgument::REQUIRED,
                "sync-all|sync-user|queue-count|queue-show|queue-clear|test|process"
            )
            ->addOption("email", null, InputOption::VALUE_OPTIONAL, "email of user to sync if syncing a user.")
            ->addOption(
                "n",
                null,
                InputOption::VALUE_OPTIONAL,
                "Number of messages to process (default 50)",
                self::QUEUE_RATE_DEFAULT
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
            $process = (int) ($input->getOption("n") ?: self::QUEUE_RATE_DEFAULT);
            switch ($action) {
                case "sync-all":
                    $this->syncAllUsers($output);
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
                    throw new \Exception("'{$action}' is not a valid action.");
            }
        } catch (\Exception $e) {
            $output->writeln(
                "<info>An error occurred:</info>\n<error>".$e->getMessage()."</error>\n".$e->getTraceAsString()
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
     * Temporary or testing functionality.
     * @param OutputInterface $output allows output to the commandline.
     */
    private function test($output)
    {
        $output->writeln("test.");
    }

    /**
     * Processes items in the queue.
     * @param OutputInterface $output is used to log to the commandline.
     * @param int             $max    is the maximum number of queue items to process.
     */
    public function process(OutputInterface $output, $max)
    {
        $counts = $this->hubspot->process($max);
        $output->writeln(sprintf(
            'Sent %s updates, %s requeued, %s dropped',
            $counts["processed"],
            $counts["requeued"],
            $counts["dropped"]
        ));
    }
}
