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
use AppBundle\Document\Subvariant;

class PricingUpdateCommand extends ContainerAwareCommand
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
    protected $subvariants;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->headers = [
            'make',
            'model',
            'memory',
            'subvariant',
            'monthlyGwp',
            'yearlyGwp',
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
        $this->subvariants = [];
    }

    protected function configure()
    {
        $this
            ->setName('sosure:phone:pricing-update')
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
                if ($update['subvariant'] == '') {
                    $this->errors[] = "subvariant is blank";
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
                    'monthlyGwp',
                    'yearlyGwp'
                ];

                foreach ($this->dm->getRepository(Subvariant::class)->findAll() as $subvariant) {
                    $this->subvariants[$subvariant->getName()] = [];
                    if ($subvariant->getLoss()) {
                        $this->subvariants[$subvariant->getName()][] = 'loss';
                        $this->subvariants[$subvariant->getName()][] = 'validatedLoss';
                    }
                    if ($subvariant->getTheft()) {
                        $this->subvariants[$subvariant->getName()][] = 'theft';
                        $this->subvariants[$subvariant->getName()][] = 'validatedTheft';
                    }
                    if ($subvariant->getDamage()) {
                        $this->subvariants[$subvariant->getName()][] = 'damage';
                        $this->subvariants[$subvariant->getName()][] = 'validatedDamage';
                    }
                    if ($subvariant->getWarranty()) {
                        $this->subvariants[$subvariant->getName()][] = 'warranty';
                        $this->subvariants[$subvariant->getName()][] = 'validatedWarranty';
                    }
                    if ($subvariant->getExtendedWarranty()) {
                        $this->subvariants[$subvariant->getName()][] = 'extendedWarranty';
                        $this->subvariants[$subvariant->getName()][] = 'validatedExtendedWarranty';
                    }
                }

                $this->subvariants['standard'] = [
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

                if (!array_key_exists($update["subvariant"], $this->subvariants)) {
                    $this->errors[] = $update["subvariant"]." is invalid subvariant";
                }

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
                    foreach ($this->subvariants[$update["subvariant"]] as $excess) {
                        $details =  $update["make"] . " " .
                                    $update["model"] . " " .
                                    $update["memory"] . " " .
                                    $update["subvariant"] . " ";
                        if ($update[$excess] == '') {
                            $this->errors[] = $details."$excess is blank";
                        } elseif (!is_numeric($update[$excess])) {
                            $this->errors[] = $details.$update[$excess]." $excess not a number";
                        } elseif (!ctype_digit($update[$excess])) {
                            $this->errors[] = $details.$update[$excess]." $excess can only be an integer";
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
        if ($update["subvariant"] == "standard") {
            // fix for the database not storing the subvariant name for standard
            $priceMonthly = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, null, null);
            $priceYearly = $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, null, null);
        } else {
            $priceMonthly = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY, null, $update["subvariant"]);
            $priceYearly = $phone->getCurrentPhonePrice(PhonePrice::STREAM_YEARLY, null, $update["subvariant"]);
        }

        $monthlyGwp = $priceMonthly->getGwp();
        $yearlyGwp = $priceYearly->getGwp();
        $excess = $priceMonthly->getExcess();
        $picSureExcess = $priceMonthly->getPicSureExcess();

        $currentValues = [
            'monthlyGwp' => $monthlyGwp,
            'yearlyGwp' => $yearlyGwp,
            'damage' => $excess->getDamage(),
            'warranty' => $excess->getWarranty(),
            'extendedWarranty' => $excess->getExtendedWarranty(),
            'loss' => $excess->getLoss(),
            'theft' => $excess->getTheft(),
            'validatedDamage' => $picSureExcess->getDamage(),
            'validatedWarranty' => $picSureExcess->getWarranty(),
            'validatedExtendedWarranty' => $picSureExcess->getExtendedWarranty(),
            'validatedLoss' => $picSureExcess->getLoss(),
            'validatedTheft' => $picSureExcess->getTheft()
        ];
        if ($this->premiumOnly || (!$this->premiumOnly && !$this->excessOnly)) {
            $monthlyGwp = $update["monthlyGwp"];
            $yearlyGwp = $update["yearlyGwp"];
        }
        if ($this->excessOnly || (!$this->premiumOnly && !$this->excessOnly)) {
            foreach ($this->subvariants[$update["subvariant"]] as $risk) {
                if ($risk == "loss") {
                    $excess->setLoss((int) $update["loss"]);
                    $picSureExcess->setLoss((int) $update["validatedLoss"]);
                }
                if ($risk == "theft") {
                    $excess->setTheft((int) $update["theft"]);
                    $picSureExcess->setTheft((int) $update["validatedTheft"]);
                }
                if ($risk == "damage") {
                    $excess->setDamage((int) $update["damage"]);
                    $picSureExcess->setDamage((int) $update["validatedDamage"]);
                }
                if ($risk == "warranty") {
                    $excess->setWarranty((int) $update["warranty"]);
                    $picSureExcess->setWarranty((int) $update["validatedWarranty"]);
                }
                if ($risk == "extendedWarranty") {
                    $excess->setExtendedWarranty((int) $update["extendedWarranty"]);
                    $picSureExcess->setExtendedWarranty((int) $update["validatedExtendedWarranty"]);
                }
            }
        }
        $this->outputChanges($phone, $currentValues, $update);
        if ($this->wet) {
            $updateDate = new \DateTime('+2 hours', SoSure::getSoSureTimezone());
            if ($update["subvariant"] == "standard") {
                // fix for the database not storing the subvariant name for standard
                $phone->changePrice(
                    $monthlyGwp,
                    $updateDate,
                    $excess,
                    $picSureExcess,
                    null,
                    "monthly",
                    null,
                    null
                );
                $phone->changePrice(
                    $yearlyGwp,
                    $updateDate,
                    $excess,
                    $picSureExcess,
                    null,
                    "yearly",
                    null,
                    null
                );
            } else {
                $phone->changePrice(
                    $monthlyGwp,
                    $updateDate,
                    $excess,
                    $picSureExcess,
                    null,
                    "monthly",
                    null,
                    $update["subvariant"]
                );
                $phone->changePrice(
                    $yearlyGwp,
                    $updateDate,
                    $excess,
                    $picSureExcess,
                    null,
                    "yearly",
                    null,
                    $update["subvariant"]
                );
            }
            $this->dm->flush();
        }
        return $phone;
    }

    private function outputChanges($phone, $currentValues, $update)
    {
        $overview = 'Update - "'.mb_strtolower(
            $update["make"].'" "'.
            $update["model"].'" "'.
            $update["memory"].'" "'.
            $update["subvariant"]
        ).'"'."\n";
        foreach ($this->headers as $header) {
            if ($header != "make"
                    && $header != "model"
                    && $header != "memory"
                    && $header != "subvariant"
                    && $header != "monthlyPrice"
                    && $header != "annualPrice") {
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
            $this->output->writeln("Please fix the above ".count($this->errors)." errors");
            exit(1);
        }
    }
}
