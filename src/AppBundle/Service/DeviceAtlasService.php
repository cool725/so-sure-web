<?php
namespace AppBundle\Service;

use AppBundle\Repository\PhoneRepository;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Document\PlayDevice;
use AppBundle\Document\Phone;

class DeviceAtlasService
{
    const KEY_MISSING = 'deviceatlas:missing';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var Client */
    protected $redis;

    /** @var MailerService */
    protected $mailerService;

    /**
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     * @param Client          $redis
     * @param MailerService   $mailerService
     */
    public function __construct(
        LoggerInterface $logger,
        DocumentManager $dm,
        Client $redis,
        MailerService $mailerService
    ) {
        $this->logger = $logger;
        $this->dm = $dm;
        $this->redis = $redis;
        $this->mailerService = $mailerService;
    }

    /**
     * @param Request $request
     *
     * @return Phone|null
     */
    public function getPhone(Request $request)
    {
        return null;
    }

    /**
     * @param Request $request
     *
     * @return bool|null
     */
    public function isMobile(Request $request)
    {
        return null;
    }

    public function getMissing()
    {
        $items = [];
        while (($item = $this->redis->lpop(self::KEY_MISSING)) != null) {
            $items[] = $item;
        }

        return $items;
    }

    public function sendAll()
    {
        $html = implode('<br>', $this->getMissing());
        if (!$html) {
            $html = 'No missing browsers';
        }
        $this->mailerService->send('Mobile browsers not in db', 'analysis@so-sure.com', $html);
    }

    /**
     * @param string $manufacturer
     * @param string $marketingName
     * @param string $model
     * @param string $osVersion
     *
     * return Phone|null
     */
    protected function getPhoneFromDetails($manufacturer, $marketingName, $model, $osVersion)
    {
        /** @var DocumentRepository $playRepo */
        $playRepo = $this->dm->getRepository(PlayDevice::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $this->dm->getRepository(Phone::class);

        if ($marketingName && mb_strlen($marketingName) > 0) {
            $names = [
                sprintf("%s %s", $manufacturer, $marketingName),
                $marketingName,
            ];
            $devices = [];
            $playDevices = $playRepo->createQueryBuilder()
                ->field('marketingName')->in($names)
                ->getQuery()->execute();
            foreach ($playDevices as $playDevice) {
                $devices[] = $playDevice->getDevice();
            }

            $phones = $phoneRepo->createQueryBuilder()
                ->field('devices')->in($devices)
                ->getQuery()->execute();
            foreach ($phones as $phone) {
                return $phone;
            }
        }

        // Known issue where iphones are not detectable
        if ($manufacturer != "Apple" && $model != "iPhone") {
            $missing = sprintf("%s %s %s %s", $manufacturer, $marketingName, $model, $osVersion);
            $this->redis->rpush(self::KEY_MISSING, $missing);
            $this->logger->debug(sprintf(
                'Mobile web browser (Phone: %s) is not in our db (or device name mismatch)',
                $missing
            ));
        }

        return $manufacturer;
    }
}
