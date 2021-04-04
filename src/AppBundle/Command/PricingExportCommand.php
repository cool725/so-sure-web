<?php

namespace AppBundle\Command;

use AppBundle\Classes\SoSure;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Document\Phone;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\PhonePrice;

class PricingExportCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;
    protected $headers;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->headers = [
            'make',
            'model',
            'memory',
            'mobMonthlyGwp',
            'mobYearlyGwp',
            'essMonthlyGwp',
            'essYearlyGwp',
            'damMonthlyGwp',
            'damYearlyGwp',
            'damage',
            'warranty',
            'extendedWarranty',
            'loss',
            'theft',
            'validatedDamage',
            'validatedWarranty',
            'validatedExtendedWarranty',
            'validatedLoss',
            'validatedTheft'
        ];
    }

    protected function configure()
    {
        $this
            ->setName('sosure:phone:pricing-export')
            ->setDescription('Export current phone prices to CSV')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        print(implode($this->headers, ','));
        echo "\n";
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();

        foreach ($phones as $phone) {
            $excess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess();
            $picSureExcess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess();

            $row=[];
            $row[] = $phone->getMake();
            $row[] = $phone->getModel();
            $row[] = $phone->getMemory();
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getGwp(), 2);
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getGwp(), 2);
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, null, 'essentials')->getGwp(), 2);
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, null, 'essentials')->getGwp(), 2);
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, null, 'damage')->getGwp(), 2);
            $row[] = number_format($phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, null, 'damage')->getGwp(), 2);
            $row[] = $excess->getDamage();
            $row[] = $excess->getWarranty();
            $row[] = $excess->getExtendedWarranty();
            $row[] = $excess->getLoss();
            $row[] = $excess->getTheft();
            $row[] = $picSureExcess->getDamage();
            $row[] = $picSureExcess->getWarranty();
            $row[] = $picSureExcess->getExtendedWarranty();
            $row[] = $picSureExcess->getLoss();
            $row[] = $picSureExcess->getTheft();

            print(implode($row,','));
            echo "\n";
        }
    }
}
