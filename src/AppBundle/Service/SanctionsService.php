<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Sanctions;
use AppBundle\Document\SanctionsMatch;
use AppBundle\Document\User;
use AppBundle\Document\Company;

class SanctionsService
{
    const MATCH_THRESHOLD = 1;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $sanctions;
    protected $sanctionsCompanies;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
    }

    private function loadSanctions()
    {
        if ($this->sanctions && count($this->sanctions) > 0) {
            return;
        }

        $this->sanctions = [];
        $this->sanctionsCompanies = [];
        $sanctionsRepo = $this->dm->getRepository(Sanctions::class);
        $sanctions = $sanctionsRepo->findAll();
        foreach ($sanctions as $sanction) {
            if ($sanction->getType() == Sanctions::TYPE_USER) {
                $data = [
                    'id' => $sanction->getId(),
                    'first' => new \DoubleMetaphone($sanction->getFirstNameSpaceless()),
                    'last' => new \DoubleMetaphone($sanction->getLastNameSpaceless()),
                    'name' => $sanction->getName()
                ];
                $this->sanctions[] = $data;
            } elseif ($sanction->getType() == Sanctions::TYPE_COMPANY) {
                $data = [
                    'id' => $sanction->getId(),
                    'companyName' => $sanction->getCompany()
                ];
                $names = explode(' ', $sanction->getCompany());
                foreach ($names as $name) {
                    $data['names'][] = new \DoubleMetaphone($name);
                }
                $this->sanctionsCompanies[] = $data;
            }
        }
    }

    public function checkUser(User $user, $skipFlush = false)
    {
        $sanctionsRepo = $this->dm->getRepository(Sanctions::class);
        $this->loadSanctions();

        $matches = [];
        if ($user->getFirstName() && $user->getLastName()) {
            $metaphone = [
                'first' => new \DoubleMetaphone($user->getFirstName()),
                'last' => new \DoubleMetaphone($user->getLastName())
            ];
            foreach ($this->sanctions as $data) {
                $distance = $this->getMinLevenshteinDoupleMetaphone($metaphone['first'], $data['first']) +
                    $this->getMinLevenshteinDoupleMetaphone($metaphone['last'], $data['last']);

                if ($distance <= self::MATCH_THRESHOLD) {
                    $matches[] = array_merge($data, ['distance' => $distance]);
                    /** @var Sanctions $sanctions */
                    $sanctions = $sanctionsRepo->find($data['id']);
                    $sanctionsMatch = new SanctionsMatch();
                    $sanctionsMatch->setSanctions($sanctions);
                    $sanctionsMatch->setDistance($distance);
                    $user->addSanctionsMatch($sanctionsMatch);
                }
            }
        }

        $user->addSanctionsCheck();

        if (!$skipFlush) {
            $this->dm->flush();
        }

        return $matches;
    }
    
    public function checkCompany(Company $company, $skipFlush = false)
    {
        $sanctionsRepo = $this->dm->getRepository(Sanctions::class);
        $this->loadSanctions();

        $metaphone = null;
        $matches = [];
        $names = explode(' ', $company->getName());
        foreach ($names as $name) {
            $metaphone['names'][] = new \DoubleMetaphone($name);
        }
        foreach ($this->sanctionsCompanies as $data) {
            $distance = 0;
            $count = 0;
            foreach ($metaphone['names'] as $name) {
                if (isset($data['names'][$count])) {
                    $distance += $this->getMinLevenshteinDoupleMetaphone($name, $data['names'][$count]);
                }
                $count++;
            }

            if ($distance <= self::MATCH_THRESHOLD) {
                $matches[] = array_merge($data, ['distance' => $distance]);
                /** @var Sanctions $sanctions */
                $sanctions = $sanctionsRepo->find($data['id']);
                $sanctionsMatch = new SanctionsMatch();
                $sanctionsMatch->setSanctions($sanctions);
                $sanctionsMatch->setDistance($distance);
                $company->addSanctionsMatch($sanctionsMatch);
            }
        }

        $company->addSanctionsCheck();

        if (!$skipFlush) {
            $this->dm->flush();
        }

        return $matches;
    }

    public function getMinLevenshteinDoupleMetaphoneString($a, $b)
    {
        return $this->getMinLevenshteinDoupleMetaphone(new \DoubleMetaphone($a), new \DoubleMetaphone($b));
    }

    public function getMinLevenshteinDoupleMetaphone($metaphone1, $metaphone2)
    {
        $distance = 10;
        $distance = min($distance, levenshtein($metaphone1->primary, $metaphone2->primary));
        $distance = min($distance, levenshtein($metaphone1->primary, $metaphone2->secondary));
        $distance = min($distance, levenshtein($metaphone1->secondary, $metaphone2->primary));
        $distance = min($distance, levenshtein($metaphone1->secondary, $metaphone2->secondary));

        return $distance;
    }
}
