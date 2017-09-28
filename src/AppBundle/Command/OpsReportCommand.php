<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class OpsReportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:ops:report')
            ->setDescription('Send an email with any daily csp violations.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $redis = $this->getContainer()->get('snc_redis.default');
        $mailer = $this->getContainer()->get('app.mailer');

        $this->sendCsp($redis, $mailer);
        $this->sendClientValidation($redis, $mailer);
        $output->writeln('Sent');
    }
    
    private function sendCsp($redis, $mailer)
    {
        $items = [];
        while (($item = $redis->lpop('csp')) != null) {
            $items[] = $item;
        }

        $html = implode('<br>', $items);
        if (!$html) {
            $html = 'No csp violations';
        }
        $mailer->send('CSP Report', 'tech@so-sure.com', $html);
    }

    private function sendClientValidation($redis, $mailer)
    {
        $items = [];
        $keys = $redis->hkeys('client-validation');
        foreach ($keys as $item) {
            $data = json_decode($item, true);
            if ($data) {
                if (isset($data['url']) && isset($data['errors'])) {
                    $time = $redis->hget('client-validation', $item);
                    if ($time) {
                        $time = new \DateTime(sprintf('@%s', $time));
                    }
                    $items[] = sprintf('%s : %s', $time ? $time->format(\DateTime::ATOM) : '?', $data['url']);
                    foreach ($data['errors'] as $error) {
                        if (isset($error['name']) && isset($error['message'])) {
                            $items[] = sprintf('%s => %s (%s)', $error['name'], isset($error['value']) ? $error['value'] : '', $error['message']);
                        } else {
                            $items[] = sprintf('Unknown format: %s', $item);                            
                        }
                    }
                } else {
                    $items[] = sprintf('Unknown format: %s', $item);
                }
                $items[] = '';
            }
            $redis->hdel('client-validation', $item);
        }

        $html = implode('<br>', $items);
        if (!$html) {
            $html = 'No client validation failures';
        }
        $mailer->send('Client Validation Failures', 'tech@so-sure.com', $html);
    }
}
