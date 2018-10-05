<?php

namespace AppBundle\Command;

use AppBundle\Listener\SanctionsListener;
use AppBundle\Service\MailerService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Sanctions;
use AppBundle\Document\User;
use AppBundle\Document\BaseCompany;
use AppBundle\Document\DateTrait;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use GuzzleHttp\Client;
use Symfony\Component\Templating\EngineInterface;

class SanctionsReportCommand extends ContainerAwareCommand
{
    use DateTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:sanctions:report')
            ->setDescription('Sanctions - send email report')
            ->addOption(
                'debug',
                null,
                InputOption::VALUE_NONE,
                'generates debug email output for testing'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Predis\Client $redis */
        $redis = $this->getContainer()->get('snc_redis.default');
        /** @var MailerService $mailer */
        $mailer = $this->getContainer()->get('app.mailer');
        $debug = $input->getOption('debug');
        $users = [];
        $companies = [];

        //fetch all items from redis
        while ($sanction = unserialize($redis->lpop(SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY))) {
            if (isset($sanction['user'])) {
                $users[] = $sanction;
                continue;
            }
            $companies[] = $sanction;
        }

        $numSanctions = (count($users)+count($companies));
        $mailer->sendTemplate(
            sprintf(
                'Daily sanctions report for %s. Sanctions to verify %s',
                date('m-d-Y'),
                $numSanctions
            ),
            'sanctions@so-sure.com',
            'AppBundle:Email:user/admin_sanctions.html.twig',
            ['users' => $users, 'companies' => $companies]
        );

        if ($debug) {
            /** @var EngineInterface $templating */
            $templating = $this->getContainer()->get('templating');
            $email = $templating->render(
                'AppBundle:Email:user/admin_sanctions.html.twig',
                ['users' => $users, 'companies' => $companies]
            );
            $output->writeln($email);
        }
        $output->writeln(sprintf('Found %s records. Mail sent.', $numSanctions));
    }
}
