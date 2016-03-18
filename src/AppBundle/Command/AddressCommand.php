<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class AddressCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:address')
            ->setDescription('Query address from our lookup provider.  There is a 5p charge per request if --id or --address is used, so use with caution')
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED,
                'id'
            )
            ->addOption(
                'address',
                null,
                InputOption::VALUE_NONE,
                'run full address check'
            )
            ->addArgument(
                'postcode',
                InputArgument::REQUIRED,
                'Postcode'
            )
            ->addArgument(
                'number',
                InputArgument::OPTIONAL,
                'House Number'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $postcode = $input->getArgument('postcode');
        $number = $input->getArgument('number');
        $useAddress = true === $input->getOption('address');
        $id = $input->getOption('id');
        $address = $this->getContainer()->get('app.address');
        if ($id) {
            $addressData = $address->retreive($id);
            print_r($addressData);
        } elseif ($useAddress) {
            $addresses = $address->getAddress($postcode, $number);
            print_r($addresses->toArray());
        } else {
            $addresses = $address->find($postcode, $number);
            print_r($addresses);
        }
    }
}
