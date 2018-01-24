<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;
use Symfony\Component\HttpFoundation\Request;

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

        $response = $this->sendCsp($redis, $mailer);
        $output->writeln(sprintf('Sent %s CSP violations', $response));
        $response = $this->sendClientValidation($redis, $mailer);
        $output->writeln(sprintf('Sent %s', $response));
    }

    private function sendCsp($redis, $mailer)
    {
        $items = [];
        while (($item = $redis->lpop('csp')) != null) {
            $items[] = $item;
        }

        $html = implode("\n<br/><br/>\n", $items);
        if (!$html) {
            $html = 'No csp violations';
        }
        $mailer->send('CSP Report', 'tech@so-sure.com', $html);
        return count($items);

    }

    private function parseErrors($errors)
    {
        $items = [];
        $emptyValues = 0;

        foreach ($errors as $error) {
            if (!isset($error['name']) || !isset($error['value'])) {
                $emptyValues++;
            } elseif (isset($error['name']) && $error['name'] == '') {
                $emptyValues++;
            } elseif (isset($error['value']) && $error['value'] == '') {
                $emptyValues++;
            }
            if (isset($error['name']) && isset($error['message'])) {
                $items[] = sprintf(
                    '%s => "%s" [Msg: %s]',
                    $error['name'],
                    isset($error['value']) ? $error['value'] : '',
                    $error['message']
                );
            } else {
                $items[] = sprintf('Unknown format: %s', json_encode($error));
            }
        }
        // exception: if all values are empty, do not include in the email
        return (count($items) == $emptyValues) ? false : $items;
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
                    // parsing errors first to se if we need to add items to the email
                    $parsedErrors = $this->parseErrors($data['errors']);
                    if ($parsedErrors) {
                        $items[] = sprintf(
                            '%s : %s [Browser: %s]',
                            $time ? $time->format(\DateTime::ATOM) : '?',
                            $data['url'],
                            isset($data['browser']) ? $data['browser'] : ''
                        );
                        $items = array_merge($items, $parsedErrors);
                        $items[] = '';
                    }
                } else {
                    // exception: ignore items with no errors
                    if (isset($data['url']) && !isset($data['errors'])) {
                        continue;
                    }

                    $items[] = sprintf('Unknown format: %s', $item);
                    $items[] = '';
                }
            }
            $redis->hdel('client-validation', $item);
        }
        $html = implode('<br>', $items);
        if (!$html) {
            $html = 'No client validation failures';
        }
        $mailer->send('Client Validation Failures', 'tech@so-sure.com', $html);
        return count($items) > 0 ? 'found validation failures' : 'no validation failures';
    }
}
