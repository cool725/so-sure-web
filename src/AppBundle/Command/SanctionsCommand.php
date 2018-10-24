<?php

namespace AppBundle\Command;

use AppBundle\Repository\UserRepository;
use AppBundle\Service\SanctionsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use AppBundle\Document\Sanctions;
use AppBundle\Document\User;
use AppBundle\Document\Company;
use AppBundle\Document\DateTrait;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use GuzzleHttp\Client;

class SanctionsCommand extends ContainerAwareCommand
{
    use DateTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var SanctionsService  */
    protected $sanctionsService;

    public function __construct(DocumentManager $dm, SanctionsService $sanctionsService)
    {
        parent::__construct();
        $this->dm = $dm;
        $this->sanctionsService = $sanctionsService;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:sanctions')
            ->setDescription('Sanctions - import file or run checks')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'uk-treasury only option supported at the moment'
            )
            ->addOption(
                'file',
                null,
                InputOption::VALUE_REQUIRED,
                'Use a local file'
            )
            ->addOption(
                'userId',
                null,
                InputOption::VALUE_REQUIRED,
                '(Re)Run a user check'
            )
            ->addOption(
                'companyId',
                null,
                InputOption::VALUE_REQUIRED,
                '(Re)Run a company check'
            )
            ->addOption(
                'url',
                null,
                InputOption::VALUE_REQUIRED,
                'Url to get source file (default to expected location',
                'http://hmt-sanctions.s3.amazonaws.com/sanctionsconlist.csv'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source = $input->getOption('source');
        $url = $input->getOption('url');
        $file = $input->getOption('file');
        $userId = $input->getOption('userId');
        $companyId = $input->getOption('companyId');
        if ($file) {
            if ($source == Sanctions::SOURCE_UK_TREASURY) {
                $this->ukTreasury($file);
            } else {
                throw new \Exception('Unknown source. see --help');
            }
        } elseif ($userId) {
            $this->checkUsers($userId);
        } elseif ($companyId) {
            $this->checkCompanies($companyId);
        } elseif ($source == Sanctions::SOURCE_UK_TREASURY) {
            $file = tempnam(sys_get_temp_dir(), 'sanc');
            $client = new Client();
            $client->request('GET', $url, ['sink' => $file]);
            $rows = $this->ukTreasury($file);
            $output->writeln(sprintf('%d records added/updated', $rows));
            unlink($file);
        } else {
            $this->checkUsers();
            $this->checkCompanies();
        }
        $output->writeln('Finished');
    }

    protected function checkUsers($userId = null)
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        if ($userId) {
            $users = [];
            $users[] = $userRepo->find($userId);
        } else {
            $users = $userRepo->findAll();
        }
        $count = 0;
        foreach ($users as $user) {
            $matches = $this->sanctionsService->checkUser($user, true);
            if ($matches) {
                print sprintf('%s %s', $user->getName(), json_encode($matches)) . PHP_EOL;
            }
            $count++;
            if ($count % 1000 == 0) {
                $this->dm->flush();
            }
        }
        $this->dm->flush();
    }

    protected function checkCompanies($companyId = null)
    {
        $companyRepo = $this->dm->getRepository(Company::class);
        if ($companyId) {
            $companies = [];
            $companies[] = $companyRepo->find($companyId);
        } else {
            $companies = $companyRepo->findAll();
        }
        foreach ($companies as $company) {
            /** @var Company $company */
            $matches = $this->sanctionsService->checkCompany($company, true);
            if ($matches) {
                print sprintf('%s %s', $company->getName(), json_encode($matches)) . PHP_EOL;
            }
        }
        $this->dm->flush();
    }

    protected function ukTreasury($file)
    {
        $row = 0;
        $date = null;
        if (($handle = fopen($file, "r")) !== false) {
            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                if ($row == 0) {
                    $date = $this->startOfDay(\DateTime::createFromFormat("d/m/Y", $data[1]));
                } elseif ($row > 1) {
                    $this->addUpdateSanctions($data, $date);
                }
                if ($row % 1000 == 0) {
                    $this->dm->flush();
                }
                $row++;
            }
            fclose($handle);
        }
        $this->dm->flush();

        return $row;
    }
    
    protected function addUpdateSanctions($data, $date)
    {
        $validator = new AlphanumericSpaceDotValidator();

        $firstName = trim($validator->conform(mb_substr($data[1], 0, 100)));
        $lastName = trim($validator->conform(mb_substr($data[0], 0, 100)));
        $birthday = null;
        if (isset($data[7])) {
            $field = trim(str_replace('"', '', $data[7]));
            if (mb_strlen($field) > 0) {
                $birthday = \DateTime::createFromFormat("d/m/Y", str_replace('00', '01', $field));
                $birthday = $this->startOfDay($birthday);
            }
        }
        // company vs person
        if ($data[1] == '' && !$birthday) {
            $this->dm->createQueryBuilder(Sanctions::class)
            ->findAndUpdate()
            ->upsert(true)
            ->field('company')->equals($lastName)
            ->field('source')->equals(Sanctions::SOURCE_UK_TREASURY)
            ->field('type')->equals(Sanctions::TYPE_COMPANY)

            ->field('company')->set($lastName)
            ->field('date')->set($date)
            ->field('source')->set(Sanctions::SOURCE_UK_TREASURY)
            ->field('type')->set(Sanctions::TYPE_COMPANY)
            ->getQuery()
            ->execute();
        } else {
            $this->dm->createQueryBuilder(Sanctions::class)
            ->findAndUpdate()
            ->upsert(true)
            ->field('firstName')->equals($firstName)
            ->field('lastName')->equals($lastName)
            ->field('birthday')->equals($birthday)
            ->field('source')->equals(Sanctions::SOURCE_UK_TREASURY)
            ->field('type')->equals(Sanctions::TYPE_USER)

            ->field('firstName')->set($firstName)
            ->field('lastName')->set($lastName)
            ->field('birthday')->set($birthday)
            ->field('date')->set($date)
            ->field('source')->set(Sanctions::SOURCE_UK_TREASURY)
            ->field('type')->set(Sanctions::TYPE_USER)
            ->getQuery()
            ->execute();
        }
    }
}
