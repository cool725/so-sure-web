<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Classes\SoSure;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Implements a command to provide billing date functionality.
 */
class BillingDateCommand extends ContainerAwareCommand
{
    const HELP = "Provides manual functionality around billing dates. <info>action</info> argument is used to select ".
        "which functionality to use.\nNo modification to data is made unless the <info>wet</info> option is set.\n".
        "<info>mass-update</info> action is used to update the billing date on all active policies to their own ".
        "billing date but at 3am, or their start date but at 3am if they lack a billing date.";

    /** @var DocumentManager */
    private $dm;

    /**
     * Injects required dependencies to command.
     * @param DocumentManager $dm is the document manager used.
     */
    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    /**
     * @Override
     */
    protected function configure()
    {
        $this->setName("sosure:schedule:billing")
            ->setDescription("Provides billing date functionality")
            ->addArgument(
                "action",
                InputArgument::REQUIRED,
                "Choices: mass-update"
            )
            ->addOption(
                "date",
                "d",
                InputOption::VALUE_REQUIRED,
                "Provide a date in format <info>d-m-Y</info>."
            )
            ->addOption(
                "time",
                "t",
                InputOption::VALUE_REQUIRED,
                "Provide a time of day in format <info>H:i</info>"
            )
            ->addOption(
                "wet",
                null,
                InputOption::VALUE_NONE,
                "If not present, potential modifications are reported but not actioned."
            )
            ->setHelp(self::HELP);
    }

    /**
     * @Override
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get arguments and options.
        $action = $input->getArgument("action");
        $date = $input->getOption("date");
        $time = $input->getOption("time");
        $wet = $input->getOption("wet") ?: false;
        // Process arguments and options.
        if ($date) {
            $date = \DateTime::createFromFormat("d-m-Y", $date);
        }
        $hour = 0;
        $minute = 0;
        if ($time) {
            $time = \DateTime::createFromFormat("H:i", $time);
            if (!$time) {
                $output->writeln("<error>Incorrect format given for option \"time\". Should be \"H:i\"");
                return;
            }
            $hour = $time->format("H");
            $minute = $time->format("i");
        }
        // Perform actions.
        if ($action == "mass-update") {
            $this->massUpdate($output, $wet);
        } else {
            $output->writeln("<error>No such action as {$action}</error>");
        }
    }

    /**
     * Performs mass-update action.
     * @param OutputInterface $output is used to output info as it goes to the user.
     * @param boolean         $wet    is whether to persist the changes or just pretend to.
     */
    private function massUpdate($output, $wet)
    {
        /** @var PolicyRepository $policyRepository */
        $policyRepository = $this->dm->getRepository(Policy::class);
        $policies = $policyRepository->findCurrentPolicies();
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            $billing = $policy->getBilling();
            $billing->setTimezone(SoSure::getSoSureTimezone());
            if ($billing) {
                if ($billing->format("H:i") == "03:00") {
                    continue;
                }
                $newBilling = clone $billing;
                $newBilling->setTime(3, 0);
                $output->writeln(sprintf(
                    "%s %s -> %s",
                    $policy->getId(),
                    $billing->format("d-m-Y H:i"),
                    $newBilling->format("d-m-Y H:i")
                ));
                if ($wet) {
                    $policy->setBillingForce($newBilling);
                    $this->dm->persist($policy);
                }
            }
        }
        if ($wet) {
            $output->writeln("Persisting changes");
            $this->dm->flush();
        }
    }
}
