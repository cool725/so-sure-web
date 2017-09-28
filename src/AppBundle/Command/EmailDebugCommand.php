<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Cashback;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\SoSure;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class EmailDebugCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:email:debug')
            ->setDescription('Send out an email of one of our templates')
            ->addOption(
                'email',
                null,
                InputOption::VALUE_REQUIRED,
                'Email to send to',
                'non-prod-web@so-sure.com'
            )
            ->addOption(
                'variation',
                null,
                InputOption::VALUE_REQUIRED,
                'Variation to use'
            )
            ->addOption(
                'options',
                null,
                InputOption::VALUE_NONE,
                'Display all options'
            )
            ->addArgument(
                'template',
                InputArgument::REQUIRED,
                'Template (e.g. cashback/approved'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $template = $input->getArgument('template');
        $email = $input->getOption('email');
        $options = true === $input->getOption('options');
        $variation = $input->getOption('variation');

        $templates = [
            'cashback' => [
                'cashback/approved-reduced',
                'cashback/approved',
                'cashback/claimed',
                'cashback/delay',
                'cashback/failed',
                'cashback/missing',
                'cashback/paid',
            ],
            'potReward' => [
                'potReward/adjusted',
            ],
            'policyConnection' => [
                'policy/connectionReduction',
            ],
            'policyRenewal' => [
                'policy/pendingRenewal',
            ],
        ];
        $variations = [
            'policyRenewal' => [
                'potIncrease',
                'potDecrease',
                'noPotIncrease',
                'noPotDecrease',
            ],
        ];

        if ($options) {
            $displayTemplates = [];
            foreach ($templates as $type => $typeTemplates) {
                $displayVariations = null;
                if (isset($variations[$type])) {
                    $displayVariations = json_encode($variations[$type]);
                }
                foreach ($typeTemplates as $typeTemplate) {
                    $displayTemplates[] = [$typeTemplate, $displayVariations];
                }
            }
            $table = new Table($output);
            $table
                ->setHeaders(array('Template', 'Variations'))
                ->setRows($displayTemplates)
            ;
            $table->render();
            return;
        }
        $data = [];
        if (in_array($template, $templates['cashback'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Cashback::class);
            $data = [
                'cashback' => $repo->findOneBy([]),
                'withdraw_url' => $this->getContainer()->get('router')->generate(
                    'homepage',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                ),
            ];
        } elseif (in_array($template, $templates['potReward'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $data = [
                'policy' => $repo->findOneBy([]),
                'additional_amount' => 10,
            ];
        } elseif (in_array($template, $templates['policyConnection'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(StandardConnection::class);
            $connection = $repo->findOneBy(['value' => ['$gt' => 0]]);
            $data = [
                'connection' => $connection,
                'policy' => $connection->getSourcePolicy(),
                'causalUser' => $connection->getLinkedUser(),
            ];
        } elseif (in_array($template, $templates['policyRenewal'])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policies = $repo->findBy(['status' => Policy::STATUS_PENDING_RENEWAL]);
            $policy = null;
            foreach ($policies as $pendingRenewal) {
                $prevPolicy = $pendingRenewal->getPreviousPolicy();
                if (!$prevPolicy || !$prevPolicy->getPremium() || !$prevPolicy->getNextPolicy() || !$prevPolicy->getNextPolicy()->getPremium()) {
                    continue;
                }
                if ($prevPolicy->getPotValue() > 0) {
                    if (in_array($variation, ['noPotIncrease', 'noPotDecrease'])) {
                        continue;
                    }
                } else {
                    if (in_array($variation, ['potIncrease', 'potDecrease'])) {
                        continue;
                    }
                }
                if ($prevPolicy->getNextPolicy()->getPremium()->getMonthlyPremiumPrice() < $prevPolicy->getPremium()->getMonthlyPremiumPrice()) {
                    if (in_array($variation, ['noPotIncrease', 'potIncrease'])) {
                        continue;
                    }
                } else {
                    if (in_array($variation, ['noPotDecrease', 'potDecrease'])) {
                        continue;
                    }
                }
                $policy = $prevPolicy;
                break;
            }
            if (!$policy || !$policy->getPremium() && !$policy->getNextPolicy() && !$policy->getNextPolicy()->getPremium()) {
                throw new \Exception('Unable to find matching policy');
            }
            $policyService = $this->getContainer()->get('app.policy');
            return $policyService->pendingRenewalEmail($policy);
        } else {
            throw new \Exception(sprintf('Unsupported template %s for email debug. Add data', $template));
        }

        $mailer = $this->getContainer()->get('app.mailer');
        $mailer->sendTemplate(
            sprintf('sosure:email:debug %s', $template),
            $email,
            sprintf('AppBundle:Email:%s.html.twig', $template),
            $data,
            sprintf('AppBundle:Email:%s.txt.twig', $template),
            $data
        );
    }
}
