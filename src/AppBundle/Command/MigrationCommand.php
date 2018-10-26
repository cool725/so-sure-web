<?php

namespace AppBundle\Command;

use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;

class MigrationCommand extends ContainerAwareCommand
{
    use DateTrait;

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
            ->setName('sosure:migrate')
            ->setDescription('Data migration')
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                'migrateOptOut, migrateOptOutCat, migrateOptOutAll'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $type = $input->getArgument('type');
        call_user_func([$this, $type]);
        $output->writeln('Finished');
    }

    private function migrateOptOut()
    {
        $repo = $this->dm->getRepository(EmailOptOut::class);
        $optOuts = $repo->findAll();
        $migrated = [];
        foreach ($optOuts as $optOut) {
            /** @var EmailOptOut $optOut */
            if ($optOut->getCategory() == 'aquire' || $optOut->getCategory() == 'retain') {
                if (in_array($optOut->getEmail(), $migrated)) {
                    $this->dm->remove($optOut);
                } else {
                    $optOut->setCategory(EmailOptOut::OPTOUT_CAT_MARKETING);
                    $migrated[] = $optOut->getEmail();
                }
            } elseif ($optOut->getCategory() == 'weekly') {
                $this->dm->remove($optOut);
            }
        }
        $this->dm->flush();
    }

    private function migrateOptOutCat()
    {
        $repo = $this->dm->getRepository(EmailOptOut::class);
        $optOuts = $repo->findAll();
        $migrated = [];
        foreach ($optOuts as $optOut) {
            /** @var EmailOptOut $optOut */
            if (array_key_exists($optOut->getEmail(), $migrated)) {
                $migrated[$optOut->getEmail()]->addCategory($optOut->getCategory());
                $this->dm->remove($optOut);
            } else {
                $optOut->addCategory($optOut->getCategory());
                $migrated[$optOut->getEmail()] = $optOut;
            }
        }
        $this->dm->flush();
    }

    private function migrateOptOutAll()
    {
        $repo = $this->dm->getRepository(EmailOptOut::class);
        $optOuts = $repo->findAll();
        $migrated = [];
        foreach ($optOuts as $optOut) {
            /** @var EmailOptOut $optOut */
            if ($optOut->getCategory() == EmailOptOut::OPTOUT_CAT_ALL) {
                $optOut->setCategory(EmailOptOut::OPTOUT_CAT_MARKETING);
                $optOut->addCategory(EmailOptOut::OPTOUT_CAT_MARKETING);
                $optOut->addCategory(EmailOptOut::OPTOUT_CAT_INVITATIONS);
            }
        }
        $this->dm->flush();
    }
}
