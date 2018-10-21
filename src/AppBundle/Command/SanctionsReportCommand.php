<?php

namespace AppBundle\Command;

use AppBundle\Listener\SanctionsListener;
use AppBundle\Service\MailerService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Sanctions;
use AppBundle\Document\User;
use AppBundle\Document\Company;
use AppBundle\Document\DateTrait;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use Symfony\Component\Templating\EngineInterface;

class SanctionsReportCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var Client */
    protected $redis;

    /** @var MailerService */
    protected $mailerService;

    /** @var EngineInterface */
    protected $templating;

    public function  __construct(Client $redis, MailerService $mailerService, EngineInterface $templating)
    {
        parent::__construct();
        $this->redis = $redis;
        $this->mailerService = $mailerService;
        $this->templating = $templating;
    }

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
        $debug = $input->getOption('debug');
        $users = [];
        $companies = [];

        //fetch all items from redis
        while ($sanction = unserialize($this->redis->lpop(SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY))) {
            if (isset($sanction['user'])) {
                $users[] = $sanction;
                continue;
            }
            $companies[] = $sanction;
        }

        $numSanctions = (count($users)+count($companies));
        $this->mailerService->sendTemplate(
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
            $email = $this->templating->render(
                'AppBundle:Email:user/admin_sanctions.html.twig',
                ['users' => $users, 'companies' => $companies]
            );
            $output->writeln($email);
        }
        $output->writeln(sprintf('Found %s records. Mail sent.', $numSanctions));
    }
}
