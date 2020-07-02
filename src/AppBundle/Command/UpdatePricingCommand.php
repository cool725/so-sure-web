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

class UpdatePricingCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;
    /** @var boolean $wet */
    protected $wet;
    /** @var boolean $premiumOnly */
    protected $premiumOnly;
    /** @var boolean $excessOnly */
    protected $excessOnly;
    /** @var boolean $ignoreMissing */
    protected $ignoreMissing;
    protected $headers;
    protected $output;
    protected $errors;
    protected $updates;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->headers = [
            'make',
            'model',
            'memory',
            'monthlyPrice',
            'annualPrice',
            'monthlyGwp',
            'annualGwp',
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
        $this->errors = [];
        $this->updates = [];
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
                'premiumOnly',
                null,
                InputOption::VALUE_NONE,
                'Only update the premium from the CSV'
            )
            ->addOption(
                'excessOnly',
                null,
                InputOption::VALUE_NONE,
                'Only update the excesses from the CSV'
            )
            ->addOption(
                'ignoreMissing',
                null,
                InputOption::VALUE_NONE,
                'Surpress the error for phones not found in DB or inactive'
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
        $csv = $input->getOption('csv');
        $this->output = $output;
        /** @var boolean $wet */
        $this->wet = $input->getOption('wet') == true;
        /** @var boolean $premiumOnly */
        $this->premiumOnly = $input->getOption('premiumOnly') == true;
        /** @var boolean $excessOnly */
        $this->excessOnly = $input->getOption('excessOnly') == true;
        /** @var boolean $ignoreMissing */
        $this->ignoreMissing = $input->getOption('ignoreMissing') == true;

        $this->readCsv($csv);
        if ($this->validateUpdates()) {
            foreach ($this->updates as $key => $update) {
                if ($key != 0) {
                    $repo = $this->dm->getRepository(Phone::class);
                    /** @var Phone $phone */
                    $phone = $repo->findOneBy([
                        'active' => true,
                        'makeCanonical' => mb_strtolower($update["make"]),
                        'modelCanonical' => mb_strtolower($update["model"]),
                        'memory' => (int) $update["memory"]
                    ]);
                    if ($phone) {
                        $this->newValues($phone, $update);
                    }
                }
            }
        }

        if (!$this->wet) {
            $this->output->writeln("THIS WAS A DRY RUN. NO CHANGES WERE MADE.");
        }

        $output->writeln('Finished. If prices were changed, it will take effect in 2 hours.');
    }

    private function readCsv($csv)
    {
        if (($handle = fopen($csv, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $this->updates[] = $row;
            }
        } else {
            $this->errors[] = "Unable to open CSV";
            $this->errors();
        }
    }

    private function validateUpdates()
    {
        if (count(array_diff($this->updates[0], $this->headers))) {
            $this->errors[] = "Incorrect headers";
            $this->errors[] = print_r(array_diff($this->updates[0], $this->headers));
            $this->errors();
        }

        foreach ($this->updates as $key => $update) {
            if ($key != 0) {
                $update = array_combine($this->headers, $update);
                $this->updates[$key] = $update;

                if ($update['make'] == '') {
                    $this->errors[] = "make is blank";
                }
                if ($update['model'] == '') {
                    $this->errors[] = "model is blank";
                }
                if ($update['memory'] == '') {
                    $this->errors[] = "memory is blank";
                }

                if (!$this->ignoreMissing) {
                    $repo = $this->dm->getRepository(Phone::class);
                    /** @var Phone $phone */
                    $phone = $repo->findOneBy([
                        'active' => true,
                        'makeCanonical' => mb_strtolower($update["make"]),
                        'modelCanonical' => mb_strtolower($update["model"]),
                        'memory' => (int) $update["memory"]
                    ]);

                    if (!$phone) {
                        $this->errors[] = 'Phone not found in DB or is inactive - "'.mb_strtolower(
                            $update["make"].'" "'.
                            $update["model"].'" "'.
                            $update["memory"]
                        ).'"';
                    }
                }

                $premiums = [
                    'monthlyPrice', // not actually used
                    'annualPrice', // not actually used
                    'monthlyGwp',
                    'annualGwp'
                ];

                $excesses = [
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

                if ($this->premiumOnly || (!$this->premiumOnly && !$this->excessOnly)) {
                    // validate the premium values
                    foreach ($premiums as $premium) {
                        if ($update[$premium] == '') {
                            $this->errors[] = "$premium is blank";
                        } elseif (!is_numeric((float) $update[$premium])) {
                            $this->errors[] = $update[$premium]." $premium not a number";
                        } elseif (mb_strlen(mb_substr(mb_strrchr($update[$premium], "."), 1)) != 2) {
                            $this->errors[] = $update[$premium]." $premium must be to 2 decimal places";
                        }
                    }
                }

                if ($this->excessOnly || (!$this->premiumOnly && !$this->excessOnly)) {
                    // validate the excess values
                    foreach ($excesses as $excess) {
                        if ($update[$excess] == '') {
                            $this->errors[] = "$excess is blank";
                        } elseif (!is_numeric($update[$excess])) {
                            $this->errors[] = $update[$excess]." $excess not a number";
                        } elseif (!ctype_digit($update[$excess])) {
                            $this->errors[] = $update[$excess]." $excess can only be an integer";
                        }
                    }
                }
            }
        }

        $this->errors();
        if (count($this->errors) == 0) {
            return true;
        } else {
            return false;
        }
    }

    private function newValues($phone, $update)
    {
        $monthlyGwp = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getGwp();
        $yearlyGwp = $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)->getGwp();
        $monthlyExcess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess();
        $monthlyPicSureExcess = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess();

        $currentValues = [
            'monthlyGwp' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)
                ->getGwp(),
            'annualGwp' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY)
                ->getGwp(),
            'damage' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess()
                ->getDamage(),
            'warranty' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess()
                ->getWarranty(),
            'extendedWarranty' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess()
                ->getExtendedWarranty(),
            'loss' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess()
                ->getLoss(),
            'theft' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getExcess()
                ->getTheft(),
            'validatedDamage' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess()
                ->getDamage(),
            'validatedWarranty' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess()
                ->getWarranty(),
            'validatedExtendedWarranty' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess()
                ->getExtendedWarranty(),
            'validatedLoss' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess()
                ->getLoss(),
            'validatedTheft' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY)->getPicSureExcess()
                ->getTheft()
        ];

        if ($this->premiumOnly || (!$this->premiumOnly && !$this->excessOnly)) {
            $monthlyGwp = $update["monthlyGwp"];
            $yearlyGwp = $update["annualGwp"];
        } elseif ($this->excessOnly || (!$this->premiumOnly && !$this->excessOnly)) {
            $monthlyExcess->setDamage((int) $update["damage"]);
            $monthlyExcess->setWarranty((int) $update["warranty"]);
            $monthlyExcess->setExtendedWarranty((int) $update["extendedWarranty"]);
            $monthlyExcess->setLoss((int) $update["loss"]);
            $monthlyExcess->setTheft((int) $update["theft"]);
            $monthlyPicSureExcess->setDamage((int) $update["validatedDamage"]);
            $monthlyPicSureExcess->setWarranty((int) $update["validatedWarranty"]);
            $monthlyPicSureExcess->setExtendedWarranty((int) $update["validatedExtendedWarranty"]);
            $monthlyPicSureExcess->setLoss((int) $update["validatedLoss"]);
            $monthlyPicSureExcess->setTheft((int) $update["validatedTheft"]);
        }

        $this->outputChanges($phone, $currentValues, $update);

        if ($this->wet) {
            $phone->changePrice(
                $monthlyGwp,
                $date = new \DateTime('+2 hour', SoSure::getSoSureTimezone()),
                $monthlyExcess,
                $monthlyPicSureExcess,
                null,
                "monthly"
            );
            $phone->changePrice(
                $yearlyGwp,
                $date = new \DateTime('+2 hour', SoSure::getSoSureTimezone()),
                $monthlyExcess,
                $monthlyPicSureExcess,
                null,
                "yearly"
            );
            $this->dm->flush();
        }

        return $phone;
    }

    private function outputChanges($phone, $currentValues, $update)
    {
        $overview = 'Update - "'.mb_strtolower(
            $update["make"].'" "'.
            $update["model"].'" "'.
            $update["memory"]
        ).'"'."\n";

        foreach ($this->headers as $header) {
            if ($header != "make"
                    && $header != "model"
                    && $header != "memory"
                    && $header != "monthlyPrice"
                    && $header != "annualPrice")
            {
                $overview .= "$header: ".$currentValues[$header]." -> ".$update[$header]."\n";
            }
        }

        $this->output->writeln($overview);
    }

    private function errors()
    {
        foreach ($this->errors as $error) {
            $this->output->writeln($error);
        }
        if (count($this->errors) > 0) {
            $this->output->writeln("Fix the above ".count($this->errors)." errors");
            exit(1);
        }
    }
}
