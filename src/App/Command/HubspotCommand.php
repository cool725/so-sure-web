<?php

namespace App\Command;

use AppBundle\Document\User;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\HubspotService;
use App\Hubspot\HubspotData;
use App\Hubspot\Api;
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
use SevenShores\Hubspot\Exceptions\BadRequest;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Allows control of the hubspot system via commandline.
 */
class HubspotCommand extends ContainerAwareCommand
{
    const HUBSPOT_SOSURE_PROPERTY_NAME = "sosure";
    const HUBSPOT_SOSURE_PROPERTY_DESC = "Custom properties used by SoSure";

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
        try {
            $this->manage($input, $output);
            return 0;
        } catch (BadRequest $e) {
            $output->writeln("<info>Hubspot returned an error:</info>\n<error>".$e->getMessage()."</error>");
            return 1;
        } catch (\Exception $e) {
            $output->writeln("<info>An error occurred:</info>\n<error>".$e->getMessage()."</error>");
            return 1;
        }
    }

    private function manage(InputInterface $input, OutputInterface $output)
    {
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
            $this->showContacts($output, $this->hubspot);
        }
        if ($email) {
            $this->requeueUserByEmail($email, $requeue, $output, $this->hubspot);
        }
        if ($queueCount) {
            $this->queueCount($output, $this->hubspot);
        }
        if ($queueShow) {
            $this->queueShow($queueProcessMaxCount, $output, $this->hubspot);
        }
        if ($queueClear) {
            $this->queueClear($input, $output, $this->hubspot);
        }
        if ($requeue) {
            $this->requeueAllUsers($output, $this->hubspot);
        }
        if ($propertiesList) {
            $this->propertiesList($output, $this->hubspot);
        }
        if ($propertiesGroup) {
            $this->propertiesSyncGroup($output);
        }
        if ($propertiesSync) {
            $this->propertiesSync($output);
        }
        $this->process($output, $queueProcessMaxCount);
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
                $hubspot->queueContact($user);
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
    }

    /**
     * Outputs a table of all properties in huubspot.
     * @param OutputInterface $output  is used to output to the commandline.
     * @param HubspotService  $hubspot is used to access the list of properties in hubspot.
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
    }

    /**
     * Makes sure that the sosure contact property group exists. Should only really need to be run once.
     * @param OutputInterface $output is used to write to the commandline.
     * @throws \Exception when something goes wrong in the process.
     */
    public function propertiesSyncGroup(OutputInterface $output)
    {
        $this->hubspot->syncPropertyGroup(self::HUBSPOT_SOSURE_PROPERTY_NAME, self::HUBSPOT_SOSURE_PROPERTY_DESC);
        $output->writeln("Successfully synced sosure property group.");
    }

    /**
     * Synchronises the list of so-sure properties onto hubspot. Should only really need to be run once.
     * @param OutputInterface $output is used to output to the commandline.
     */
    public function propertiesSync(OutputInterface $output)
    {
        $properties = $this->allPropertiesWithGroup();
        foreach ($properties as $property) {
            $name = $property["name"];
            if ($this->hubspot->syncProperty($property)) {
                $output->writeln("Created <info>{$name}</info> property.");
            } else {
                $output->writeln("Skipped <info>{$name}</info> property.");
            }
        }
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
     * Moves some stuff about.
     * @param \stdClass $property  is a thingy with properties.
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

    /**
     * Fields that, if they do not exist, will be created as properties in the 'sosure' group
     * @return array containing property data formatted for hubspot.
     */
    private function allPropertiesWithGroup()
    {
        return [
            [
                "name" => "gender",
                "label" => "gender",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "radio",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "male", "value" => "male"],
                    ["label" => "female", "value" => "female"],
                    ["label" => "x/not-known", "value" => "x"]
                ]
            ],
            [
                "name" => "date_of_birth",
                "label" => "Date of birth",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "date",
                "fieldType" => "date",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "facebook",
                "label" => "Facebook?",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "checkbox",
                "formField" => false,
                "displayOrder" => -1,
                "options" => [
                    ["label" => "yes", "value" => "yes"],
                    ["label" => "no", "value" => "no"]
                ],
            ],
            [
                "name" => "billing_address",
                "label" => "Billing address",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "textarea",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "census_subgroup",
                "label" => "Estimated census_subgroup",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "total_weekly_income",
                "label" => "Estimated total_weekly_income",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "attribution",
                "label" => "attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "latestattribution",
                "label" => "Latest attribution",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "string",
                "fieldType" => "text",
                "formField" => false,
                "displayOrder" => -1
            ],
            [
                "name" => "sosure_lifecycle_stage",
                "label" => "SO-SURE lifecycle stage",
                "description" => "Current stage in purchase-flow",
                "groupName" => self::HUBSPOT_SOSURE_PROPERTY_NAME,
                "type" => "enumeration",
                "fieldType" => "select",
                "formField" => true,
                "displayOrder" => -1,
                "options" => [
                    ["label" => Api::QUOTE, "value" => Api::QUOTE],
                    ["label" => Api::READY_FOR_PURCHASE, "value" => Api::READY_FOR_PURCHASE],
                    ["label" => Api::PURCHASED, "value" => Api::PURCHASED],
                    ["label" => Api::RENEWED, "value" => Api::RENEWED],
                    ["label" => Api::CANCELLED, "value" => Api::CANCELLED],
                    ["label" => Api::EXPIRED, "value" => Api::EXPIRED]
                ]
            ]
        ];
    }
}
