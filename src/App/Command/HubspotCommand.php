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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Doctrine\ODM\MongoDB\DocumentManager;


/**
 * Allows control of the hubspot system via commandline.
 */
class HubspotCommand extends ContainerAwareCommand
{
    private const COMMAND_NAME = "sosure:hubspot";
    protected static $defaultName = self::COMMAND_NAME;
    const QUEUE_RATE_DEFAULT = 50;
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
        $this->setDescription("Sync with HubSpot")
            ->addOption("list", null, InputOption::VALUE_NONE, "List users found in Hubspot")
            ->addOption("email", null, InputOption::VALUE_REQUIRED, "email address to sync")
            ->addOption("requeue", null, InputOption::VALUE_NONE, "Requeue user (if email param given) or all users")
            ->addOption("queue-count", null, InputOption::VALUE_NONE, "Count the queue")
            ->addOption("queue-show", null, InputOption::VALUE_NONE, "Show items in the queue")
            ->addOption("queue-clear", null, InputOption::VALUE_NONE, "Clear the queue (WARNING!!)")
            ->addOption(
                "queue-process",
                null,
                InputOption::VALUE_OPTIONAL,
                "Max Number to process",
                self::QUEUE_RATE_DEFAULT
            )
            ->addOption("properties-list", null, InputOption::VALUE_NONE, "List Hubspot properties. Can use -v")
            ->addOption("properties-group", null, InputOption::VALUE_NONE, "Check if sosure group exists on Hubspot.")
            ->addOption("properties-sync", null, InputOption::VALUE_NONE, "Sync our properties to Hubspot.");
    }

    /**
     * Entrypoint of command execution.
     * @param InputInterface  $input  gives access to commandline input.
     * @param OutputInterface $output gives access to write to commandline.
     * @return int success code.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->loggingToConsole($output);
        $list = true === $input->getOption("list");
        $email = $input->getOption("email");
        $requeue = $input->getOption("requeue");
        $queueCount = true === $input->getOption("queue-count");
        $queueShow = true === $input->getOption("queue-show");
        $queueClear = true === $input->getOption("queue-clear");
        $queueProcessMaxCount = (int) ($input->getOption("queue-process") ?? self::QUEUE_RATE_DEFAULT);
        $propertiesList = $input->getOption("properties-list");
        $propertiesGroup = $input->getOption("properties-group");
        $propertiesSync = $input->getOption("properties-sync");
        if ($list) {
            return $this->showContacts($output, $this->hubspot);
        }
        if ($email) {
            return $this->requeueUserByEmail($email, $requeue, $output, $this->hubspot);
        }
        if ($queueCount) {
            return $this->queueCount($output, $this->hubspot);
        }
        if ($queueShow) {
            return $this->queueShow($queueProcessMaxCount, $output, $this->hubspot);
        }
        if ($queueClear) {
            return $this->queueClear($input, $output, $this->hubspot);
        }
        if ($requeue) {
            return $this->requeueAllUsers($output, $this->hubspot);
        }
        if ($propertiesList) {
            return $this->propertiesList($output, $this->hubspot);
        }
        if ($propertiesGroup) {
            return $this->propertiesSyncGroup($output);
        }
        if ($propertiesSync) {
            return $this->propertiesSync($output);
        }
        return $this->process($output, $queueProcessMaxCount);
    }

    /**
     * Displays all contacts on the commandline.
     * @param OutputInterface $output  is the commandline output used to display the data.
     * @param HubspotService  $hubspot is the hubspot service used to access the data.
     * @return int 0 (denoting success).
     */
    public function showContacts(OutputInterface $output, HubspotService $hubspot)
    {
        $table = new Table($output);
        $table->setHeaders(["VID", "First Name", "Last Name"]);
        $contacts = $hubspot->getAllContacts([]);
        foreach ($contacts as $contact) {
            $table->addRow([
                $contact->vid,
                $contact->properties->firstname->value,
                $contact->properties->lastname->value,
            ]);
        }
        $table->render();
        return 0;
    }

    /**
     * Finds a user by their email address and adds them to the queue.
     * @param string          $email   is the user's email address.
     * @param boolean         $requeue tells whether to requeue or do some other mystery thing. TODO: dunno lol.
     * @param OutputInterface $output  is used to output status information.
     * @param HubspotService  $hubspot is the owner of the queue that they are being placed on.
     * @return int 1 if there is an error and otherwise 0.
     */
    public function requeueUserByEmail($email, $requeue, OutputInterface $output, HubspotService $hubspot)
    {
        $output->write("Syncing user: <comment>$email</comment> ");
        try {
            $user = $this->getUser($email);
            if ($requeue) {
                $hubspot->queue($user);
                $output->writeln(sprintf("Added to queue userId: %s", $user->getId()));
                return 0;
            }
            $responseObj = $hubspot->createOrUpdateContact($user);
            $output->writeln("created the contact.");
            return 0;
        } catch (\Exception $e) {
            echo $output->writeln(sprintf("\nException thrown:\n<comment>%s</comment>", $e));
            return 1;
        }
    }

    /**
     * Gives the length of the queue.
     * @param OutputInterface $output  is used to output the number.
     * @param HubspotService  $hubspot is the owner of the queue we are looking at.
     * @return int 0 (denoting success).
     */
    public function queueCount(OutputInterface $output, HubspotService $hubspot)
    {
        $count = $hubspot->countQueue();
        $output->writeln(sprintf("%d in queue", $count));
        return 0;
    }

    /**
     * Displays the queue.
     * @param int             $max     is the maximum number of queue items to show.
     * @param OutputInterface $output  is used to output to the commandline.
     * @param HubspotService  $hubspot contains the queue being shown.
     * @return int 0 (denoting success).
     */
    public function queueShow($max, OutputInterface $output, HubspotService $hubspot)
    {
        $data = $hubspot->getQueueData($max);
        $output->writeln(sprintf("Queue Size: %d", count($data)));
        foreach ($data as $line) {
            $output->writeln(json_encode(unserialize($line), JSON_PRETTY_PRINT));
        }
        return 0;
    }

    /**
     * Deletes everything in the queue.
     * @param InputInterface  $input   used to take user confirmation from the commandline.
     * @param OutputInterface $output  used to output to the commandline.
     * @param HubspotService  $hubspot contains the queue to be cleared.
     * @return int 1 if the user cancels, and 0 if they go ahead with it.
     */
    public function queueClear(InputInterface $input, OutputInterface $output, HubspotService $hubspot)
    {
        $helper = $this->getHelper("question");
        $question = new ConfirmationQuestion(
            "Are you sure you want to clear the queue? (y/n) ",
            false,
            "/^(y|n)/i"
        );
        if ($helper->ask($input, $output, $question)) {
            $hubspot->clearQueue();
            $output->writeln(sprintf("Queue is cleared"));
            return 0;
        }
        $output->writeln(sprintf("Cancelled"));
        return 1;
    }

    /**
     * Puts every single user in the database onto the queue.
     * @param OutputInterface $output  is used to output to the commandline.
     * @param HubspotService  $hubspot contains the queue.
     * @return 0 (denoting success).
     */
    public function requeueAllUsers(OutputInterface $output, HubspotService $hubspot)
    {
        $count = 0;
        $users = $this->dm->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            $hubspot->queueContact($user);
            $count++;
        }
        $output->writeln(sprintf("Queued %d Users", $count));
        return 0;
    }

    /**
     * Outputs a table of all properties in huubspot.
     * @param OutputInterface $output is used to output to the commandline.
     * @param HubspotService $hubspot is used to access the list of properties in hubspot.
     * @return int 0 (denoting success).
     */
    public function propertiesList(OutputInterface $output, HubspotService $hubspot)
    {
        $properties = $hubspot->getProperties();
        $table = new Table($output);
        $tableHeaders = ["Name", "Group", "Type", "Field"];
        if ($output->isVerbose()) {
            $tableHeaders = ["Name", "Label", "Group", "Type", "Field", "Description"];
        }
        $table->setHeaders($tableHeaders);
        sort($properties);
        foreach ($properties as $property) {
            $tableRow = $this->fillTableRowWithProperty($property, $output->isVerbose());
            $table->addRow($tableRow);
        }
        $table->render();
        return 0;
    }

    /**
     * Makes sure that the sosure contact property group exists. Should only really need to be run once.
     * @param OutputInterface $output is used to write to the commandline.
     * @return int 0 (denoting success).
     */
    public function propertiesSyncGroup(OutputInterface $output)
    {
        echo $output->writeln(sprintf('<comment>%s</comment>', $this->hubspot->syncPropertyGroup()));
        return 0;
    }

    /**
     * Synchronises the list of so-sure properties onto hubspot. Should only really need to be run once.
     * @param OutputInterface $output is used to output to the commandline.
     * @return int 0 (denoting success).
     */
    public function propertiesSync(OutputInterface $output): int
    {
        $this->hubspot->syncPropertyGroup();
        $actions = $this->hubspot->syncProperties();
        foreach ($actions as $action) {
            echo $output->writeln($action);
        }
        return 0;
    }

    /**
     * Processes items in the queue.
     * @param OutputInterface $output is used to log to the commandline.
     * @param int             $max    is the maximum number of queue items to process.
     * @return int 0 (denoting success).
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
        return 0;
    }

    /**
     * Moves some stuff about, TODO: I reckon this is not so good but my mind is not up to removing it right now.
     * @param \stdClass $property is a thingy with properties.
     * @param boolean   $isVerbose tells whether to pull out the basic set or the big set of properties.
     * @return array containing the properties you chose in the right order and such.
     */
    private function fillTableRowWithProperty($property, $isVerbose)
    {
        if ($isVerbose) {
            return [
                $property->name,
                $property->label,
                $property->groupName,
                $property->type,
                $property->fieldType,
                $property->description,
            ];
        }
        return [
            $property->name,
            $property->groupName,
            $property->type,
            $property->fieldType,
        ];
    }

    /**
     * Modifies the hubspot service to make it's output logs go to the commandline.
     * TODO: seems like a kinda nasty idea tbh.
     */
    private function loggingToConsole(OutputInterface $output)
    {
        $verbosityLevelMap = array(
            LogLevel::NOTICE => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::INFO   => OutputInterface::VERBOSITY_NORMAL,
            LogLevel::DEBUG => OutputInterface::VERBOSITY_VERBOSE,
        );
        $this->logger = new ConsoleLogger($output, $verbosityLevelMap);
        $this->hubspot->setLogger($this->logger);
    }

    /**
     * Gets a user by their email address.
     * @param string $email is the mentioned email address.
     * @return User who was just found.
     * @throws \UsernameNotFoundException when the user cannot actually be found.
     */
    private function getUser($email)
    {
        $repo = $this->dm->getRepository(User::class);
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        if (!$user) {
            throw new UsernameNotFoundException('Unable to find user');
        }
        return $user;
    }
}
