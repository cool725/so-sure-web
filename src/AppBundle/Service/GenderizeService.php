<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use GuzzleHttp\Client;

class GenderizeService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

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

    public function run($maxNumber = 50, $threshold = 0.8)
    {
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findBy(['gender' => null, 'firstName' => ['$ne' => null]]);
        $count = 0;
        $gender = [];
        foreach ($users as $user) {
            $this->userGender($user, $threshold);
            $gender[$user->getName()] = $user->getGender();
            $count++;
            if ($count > $maxNumber) {
                break;
            }
        }
        $this->dm->flush();

        return $gender;
    }

    public function userGender(User $user, $threshold = 0.8)
    {
        try {
            if (!$user->getFirstName()) {
                return null;
            }
    
            if ($gender = $this->query($user->getFirstName(), $threshold)) {
                $user->setGender($gender);
            } else {
                $user->setGender(User::GENDER_UNKNOWN);
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Unable to set gender for %s', $user->getFirstName()),
                ['exception' => $e]
            );
        }
    }
    
    public function query($name, $threshold = 0.8)
    {
        if (mb_strtolower($name) == "mohammed") {
            return "male";
        }
        $url = sprintf('https://api.genderize.io/?%s', http_build_query(['name' => $name]));
        $client = new Client();
        $res = $client->request('GET', $url);

        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Genderize response: %s', $body));
        $data = json_decode($body, true);
        if (!isset($data['probability']) || $data['probability'] < $threshold) {
            return null;
        }

        return $data['gender'];
    }
}
