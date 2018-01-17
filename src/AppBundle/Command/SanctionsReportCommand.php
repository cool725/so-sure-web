<?php

namespace AppBundle\Command;

use AppBundle\Listener\SanctionsListener;
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
use GuzzleHttp\Client;

class SanctionsCommand extends BaseCommand
{
    use DateTrait;

    protected function configure()
    {
        $this
            ->setName('sosure:sanctions:report')
            ->setDescription('Sanctions - send email report')
        ;
    }
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer('snc_redis.default');
        $mailer = $this->getContainer('app.mailer');
        $users = [];
        $companies = [];

        //fetch all items from redis
        while ($sanction = unserialize($redis->hpop(SanctionsListener::SANCTIONS_LISTENER_REDIS_KEY))) {
            if (isset($sanction['user'])) {
                $users[] = $sanction;
                continue;
            }
            $companies[] = $sanction;
        }

        $numSanctions = (count($users)+count($companies);

        $mailer->sendTemplate(
            sprintf(
                'Daily sanctions report for %s. Sanctions to verify %s',
                date(),
                $numSanctions)
            ),
            'tech@so-sure.com',
            'AppBundle:Email:user/admin_sanctions.html.twig',
            ['users' => $users, 'companies' => $companies]
        );
    }

}
