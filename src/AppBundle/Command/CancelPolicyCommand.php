<?php

namespace AppBundle\Command;

use AppBundle\Document\Policy;
use AppBundle\Service\PolicyService;
use AppBundle\Repository\PolicyRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for automatically cancelling a list of policies.
 */
class CancelPolicyCommand extends ContainerAwareCommand
{
    /** @var DocumentManager */
    private $dm;

    /** @var PolicyService $policyService */
    private $policyService;

    /**
     * Creates the command and puts in the dependencies.
     * @param DocumentManager $dm            is the document manager it shall use.
     * @param PolicyService   $policyService is the policy service for cancelling.
     */
    public function __construct(DocumentManager $dm, PolicyService $policyService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->policyService = $policyService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:policy:cancel')
            ->setDescription('Cancels a list of policies')
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'The reason given for cancellation. Must be a valid reason.'
            )
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'Wihtout this option no changes are persisted.'
            )
            ->addArgument('ids', InputArgument::IS_ARRAY, 'id of policy to cancel.');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Get and validate arguments.
        $reason = $input->getOption('reason');
        $wet = $input->getOption('wet') == true;
        $ids = $input->getArgument('ids');
        if (!in_array($reason, Policy::CANCELLED_REASONS)) {
            $output->writeln("<error>{$reason} is not a valid cancellation reason.</error>");
            return;
        }
        // cancel policies.
        $success = [];
        $invalid = [];
        $impossible = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        foreach ($ids as $id) {
            /** @var Policy $policy */
            $policy = $policyRepo->findOneBy(['_id' => $id]);
            if (!$policy) {
                $invalid[] = $id;
            } elseif (!$policy->canCancel($reason)) {
                $impossible[] = $id;
            } else {
                $success[] = $id;
                if ($wet) {
                    $this->policyService->cancel($policy, $reason, false, null, true);
                }
            }
        }
        // Do some nice output.
        foreach ($success as $id) {
            $output->writeln("{$id} is successfully cancelled.");
        }
        foreach ($invalid as $id) {
            $output->writeln("<error>{$id} is not a valid policy id.</error>");
        }
        foreach ($impossible as $id) {
            $output->writeln(
                "<info>{$id} is not allowed to be cancelled for {$reason}. Action must be taken manually.</info>"
            );
        }
        $output->writeln(sprintf(
            '%d cancelled, %d invalid, %d impossible',
            count($success),
            count($invalid),
            count($impossible)
        ));
        if (!$wet) {
            $output->writeln('<info>Dry Run. No changes persisted.</info>');
        }
    }
};
