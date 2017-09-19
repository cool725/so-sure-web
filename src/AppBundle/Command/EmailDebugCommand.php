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
            ->addArgument(
                'template',
                InputArgument::REQUIRED,
                'Template (e.g. cashback/approved)'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $template = $input->getArgument('template');
        $email = $input->getOption('email');

        $data = [];
        if (in_array($template, [
            'cashback/approved-reduced',
            'cashback/approved',
            'cashback/claimed',
            'cashback/delay',
            'cashback/failed',
            'cashback/missing',
            'cashback/paid',
        ])) {
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
        } elseif (in_array($template, [
            'potReward/adjusted',
        ])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $data = [
                'policy' => $repo->findOneBy([]),
                'additional_amount' => 10,
            ];
        } elseif (in_array($template, [
            'policy/connectionReduction',
        ])) {
            $dm = $this->getManager();
            $repo = $dm->getRepository(StandardConnection::class);
            $connection = $repo->findOneBy(['value' => ['$gt' => 0]]);
            $data = [
                'connection' => $connection,
                'policy' => $connection->getSourcePolicy(),
                'causalUser' => $connection->getLinkedUser(),
            ];
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
