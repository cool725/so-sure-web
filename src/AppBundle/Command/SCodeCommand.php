<?php

namespace AppBundle\Command;

use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\SCodeRepository;
use AppBundle\Service\BranchService;
use AppBundle\Service\RouterService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\DateTrait;

class SCodeCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }


    protected function configure()
    {
        $this
            ->setName('sosure:scode')
            ->setDescription('Show/Update scode link')
            ->addOption(
                'policyNumber',
                null,
                InputOption::VALUE_REQUIRED,
                'Update scode link in Branch'
            )
            ->addOption(
                'update-type',
                null,
                InputOption::VALUE_REQUIRED,
                'db to update link to scode; branch to update url in branch'
            )
            ->addOption(
                'update-source',
                null,
                InputOption::VALUE_REQUIRED,
                'google, branch'
            )
            ->addOption(
                'update-date',
                null,
                InputOption::VALUE_REQUIRED,
                '(re-)update if not update before'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $lines = [];
        $policyNumber = $input->getOption('policyNumber');
        $updateType = $input->getOption('update-type');
        $updateSource = $input->getOption('update-source');
        $updateDate = $input->getOption('update-date') ?
            new \DateTime($input->getOption('update-date')) :
            new \DateTime();
        $updateDate = $this->startOfDay($updateDate);

        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        /** @var SCodeRepository $scodeRepo */
        $scodeRepo = $this->dm->getRepository(SCode::class);

        if ($policyNumber) {
            /** @var Policy $policy */
            $policy = $policyRepo->findOneBy(['policyNumber' => $policyNumber]);
            if (!$policy) {
                throw new \Exception(sprintf('Unable to find policy for %s', $policyNumber));
            }
            $scode = $policy->getStandardSCode();
            $this->printSCode($output, $scode);
            $this->updateSCode($output, $scode, $updateType, $policyNumber);
            $this->dm->flush();
        } else {
            if ($updateSource == 'google') {
                $scodes = $scodeRepo->getLinkPrefix('https://goo.gl');
            } elseif ($updateSource == 'branch') {
                $scodes = $scodeRepo->getLinkPrefix($this->getContainer()->getParameter('branch_domain'));
            } else {
                $scodes = $scodeRepo->findAll();
            }
            $count = 1;
            foreach ($scodes as $scode) {
                if ($scode->getUpdatedDate() && $scode->getUpdatedDate() >= $updateDate) {
                    continue;
                }
                if ($updateType) {
                    $this->updateSCode($output, $scode, $updateType);
                }
                if ($count % 100 == 0) {
                    $this->dm->flush();
                }
            }
            $this->dm->flush();
        }

        $output->writeln(implode(PHP_EOL, $lines));
        $output->writeln('Finished');
    }

    private function updateSCode($output, $scode, $updateType, $policyNumber = null)
    {
        if (!$scode) {
            throw new \Exception(sprintf('Unable to find scode for policy %s', $policyNumber));
        }
        /** @var BranchService $branch */
        $branch = $this->getContainer()->get('app.branch');
        /** @var RouterService $routerService */
        $routerService = $this->getContainer()->get('app.router');
        if ($updateType == 'db') {
            $shareLink = $branch->generateSCode($scode->getCode());
            $scode->setShareLink($shareLink);
        } elseif ($updateType == 'branch') {
            if (mb_stripos($scode->getShareLink(), $this->getContainer()->getParameter('branch_domain')) !== false) {
                $branch->update($scode->getShareLink(), [
                    '$desktop_url' => $routerService->generateUrl('scode', ['code' => $scode->getCode()]),
                ]);
                $scode->setUpdatedDate(new \DateTime());
            } else {
                $output->writeln(sprintf('%s is not a branch url', $scode->getShareLink()));
            }
        } else {
            throw new \Exception('Unknown update-type');
        }
        $this->printSCode($output, $scode);
    }

    private function printSCode($output, $scode)
    {
        $output->writeln(sprintf(
            '%s %s %s',
            $scode->getPolicy()->getPolicyNumber(),
            $scode->getCode(),
            $scode->getShareLink()
        ));
    }
}
