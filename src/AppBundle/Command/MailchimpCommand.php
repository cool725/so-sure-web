<?php

namespace AppBundle\Command;

use AppBundle\Service\MailchimpService;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MailchimpCommand extends ContainerAwareCommand
{
    /** @var MailchimpService */
    protected $mailchimp;

    public function __construct(MailchimpService $mailchimp)
    {
        parent::__construct();
        $this->mailchimp = $mailchimp;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:mailchimp:add')
            ->setDescription('Add user to mailchimp')
            ->addArgument(
                'email',
                InputArgument::REQUIRED,
                'Email to add'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $email = $input->getArgument('email');
        if ($this->mailchimp->subscribe($email)) {
            $output->writeln('Added');
        } else {
            $output->writeln('User already exists');
        }
    }
}
