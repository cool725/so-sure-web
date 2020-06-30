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
use AppBundle\Document\PolicyTerms;
use AppBundle\Repository\PolicyTermsRepository;
use AppBundle\Document\PhonePrice;

class UpdatePricingCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:phone:pricing')
            ->setDescription('Add new pricing to phones from CSV')
            ->addOption(
                'csv',
                null,
                InputOption::VALUE_REQUIRED,
                'Full path to new prices CSV'
            )
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'Wihtout this option no changes are persisted.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var boolean $wet */
        $wet = $input->getOption('wet') == true;
        $csv = $input->getOption('csv');

        if (($handle = fopen($csv, 'r')) !== false) {
            $output->writeln('file opened successfully');
            $header = null;
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $header = $row;
                    $output->writeln('make, model, memory, priceMonthly, priceAnnually, GWPMonthly, GWPAnnually');
                } else {
                    $line = array_combine($header, $row);
                    $repo = $this->dm->getRepository(Phone::class);
                    $phone = $repo->findOneBy([
                        'active' => true,
                        'makeCanonical' => mb_strtolower($line["make"]),
                        'modelCanonical' => mb_strtolower($line["model"]),
                        'memory' => (int) $line["memory"]
                    ]);
                    if ($phone) {
                        if (!$wet) {
                            $output->writeln(
                                'New price - "'.mb_strtolower(
                                    $line["make"].'" "'.
                                    $line["model"].'" "'.
                                    $line["memory"]
                                ).'"'
                            );
                            $output->writeln(print_r($line));
                        } else {
                            if ($phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getGwp()
                                    == $line["GWPMonthly"]
                                && $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getGwp()
                                    == $line["GWPAnnually"]) {
                                $output->writeln(
                                    'Price unchanged - "'.mb_strtolower(
                                        $line["make"].'" "'.
                                        $line["model"].'" "'.
                                        $line["memory"]
                                    ).'"'
                                );
                            } else {
                                $phone->changePrice(
                                    $line["GWPMonthly"],
                                    $date = new \DateTime('+2 hour', SoSure::getSoSureTimezone()),
                                    $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess(),
                                    $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSUreExcess(),
                                    null,
                                    "monthly"
                                );
                                $phone->changePrice(
                                    $line["GWPAnnually"],
                                    $date = new \DateTime('+2 hour', SoSure::getSoSureTimezone()),
                                    $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getExcess(),
                                    $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getPicSUreExcess(),
                                    null,
                                    "yearly"
                                );
                            }
                        }
                    } else {
                        $output->writeln(
                            'Phone NOT found - "'.mb_strtolower(
                                $line["make"].'" "'.
                                $line["model"].'" "'.
                                $line["memory"]
                            ).'"'
                        );
                    }
                }
            }
            $this->dm->flush();
        } else {
            $output->writeln('Cannot open CSV');
        }

        $output->writeln('Finished. If prices were changed, it will take effect in 2 hours.');
    }
}
