<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use AppBundle\Document\Phone;
use AppBundle\Document\LostPhone;
use Doctrine\ODM\MongoDB\DocumentManager;

class BaseImeiService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $redis;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param DocumentManager $dm
     */
    public function setDm($dm)
    {
        $this->dm = $dm;
    }

    public function setRedis($redis)
    {
        $this->redis = $redis;
    }

    /**
     * Check if imei has be registered as lost
     *
     * @param string $imei
     *
     * @return boolean
     */
    public function isLostImei($imei)
    {
        $repo = $this->dm->getRepository(LostPhone::class);
        $phones = $repo->findBy(['imei' => (string) $imei]);

        return count($phones) > 0;
    }

    /**
     * @param string $imei
     *
     * @return boolean
     */
    public function isImei($imei)
    {
        return $this->isLuhn($imei) && strlen($imei) == 15;
    }

    /**
     * @see http://stackoverflow.com/questions/4741580/imei-validation-function
     * @param string $n
     *
     * @return boolean
     */
    protected function isLuhn($n)
    {
        $str = '';
        foreach (str_split(strrev((string) $n)) as $i => $d) {
            $str .= $i %2 !== 0 ? $d * 2 : $d;
        }
        return array_sum(str_split($str)) % 10 === 0;
    }
}
