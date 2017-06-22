<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class CspCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:csp')
            ->setDescription('Send an email with any daily csp violations.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer()->get('snc_redis.default');
        $items = [];
        while (($item = $this->redis->lpop('csp')) != null) {
            $items[] = $item;
        }

        $html = implode('<br>', $items);
        if (!$html) {
            $html = 'No csp violations';
        }
        $this->getContainer()->get('app.mailer')->send('CSP Report', 'tech@so-sure.com', $html);
        $output->writeln('Sent');
    }
}
