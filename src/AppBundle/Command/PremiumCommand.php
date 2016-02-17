<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Classes\Premium;

class PremiumCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('sosure:premium:calculator')
            ->setDescription('Display premium values')
            ->addArgument(
                'premium',
                InputArgument::REQUIRED,
                'Premium / month'
            )
            ->addArgument(
                'discount',
                InputArgument::REQUIRED,
                'Discount / month'
            )
            ->addArgument(
                'pot',
                InputArgument::REQUIRED,
                'Pot Value / month'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $premium = $input->getArgument('premium');
        $discount = $input->getArgument('discount');
        $pot = $input->getArgument('pot');
        $premiumCalc = new Premium($premium, $discount, $pot);
        
        $table = new Table($output);
        $table
            ->setHeaders(array('Premium', 'User Pays', 'Broker Fee', 'GWP', 'IPT', 'NWT', 'Payout', 'Reserve IPT'))
            ->setRows(array(array(
                $premiumCalc->getPremium(),
                $premiumCalc->getUserPayment(),
                $premiumCalc->getBrokerFee(),
                $premiumCalc->getGWP(),
                $premiumCalc->getIPT(),
                $premiumCalc->getNWT(),
                $premiumCalc->getPayout(),
                $premiumCalc->getReserveIPT(),
            )));
        $table->render();
    }
}
