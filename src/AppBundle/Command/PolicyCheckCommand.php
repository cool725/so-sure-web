<?php

namespace AppBundle\Command;

use Twig\Environment;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Policy;
use AppBundle\Service\FraudService;

/**
 * Tries to check a policy for errors.
 */
class PolicyCheckCommand extends ContainerAwareCommand
{
    private $dm;
    private $twig;
    private $fraudService;

    /**
     * @param DocumentManager $dm document manager to use in the command.
     */
    public function __construct(DocumentManager $dm, Environment $twig, FraudService $fraudService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->twig = $twig;
        $this->fraudService = $fraudService;
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName('sosure:policy:check')
            ->setDescription('Checks if a policy is all good')
            ->addArgument('id', InputArgument::REQUIRED, 'id of policy to check');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $policyRepo = $this->dm->getRepository(Policy::class);
        $id = $input->getArgument('id');
        $policy = $policyRepo->findOneById($id);
        $checks = $this->fraudService->runChecks($policy);
        echo $this->twig->render('AppBundle:Claims:claimsPolicyItem.html.twig', [
            'policy' => $policy,
            'now' => new \DateTime(),
            'fraud' => $checks,
            'policy_route' => 'claims_policy',
            'user_route' => 'claims_user',
            'policy_history' => [],
            'user_history' => []
        ]);
    }
}
