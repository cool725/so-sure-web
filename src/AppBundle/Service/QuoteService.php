<?php

namespace AppBundle\Service;

use AppBundle\Document\PlayDevice;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\Phone;
use AppBundle\Service\MailerService;
use Predis\Client;

class QuoteService
{
    const DUPLICATE_EMAIL_CACHE_TIME = 3600;
    const REDIS_UNKNOWN_EMAIL_KEY_FORMAT = 'UNKNOWN-DEVICE:%s';
    const REDIS_DIFFERENT_MAKE_EMAIL_KEY_FORMAT = 'DIFFERENT-MAKE:%s-%s';
    const REDIS_ROOTED_DEVICE_EMAIL_KEY_FORMAT = 'ROOTED-DEVICE:%s-%s';

    /** @var MailerService */
    protected $mailer;
    protected $dm;
    protected $redis;

    /**
     * @param MailerService   $mailer
     * @param DocumentManager $dm
     * @param Client          $redis
     */
    public function __construct(MailerService $mailer, DocumentManager $dm, Client $redis)
    {
        $this->mailer = $mailer;
        $this->dm = $dm;
        $this->redis = $redis;
    }

    public function getQuotes($make, $device, $memory = null, $rooted = null, $ignoreMake = false)
    {
        // TODO: We should probably be checking make as well.  However, we need to analyze the data
        // See Phone::isSameMake()
        \AppBundle\Classes\NoOp::ignore([$make]);
        $repo = $this->dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $device]);
        $anyActive = false;
        $anyRetired = false;
        $anyPricing = false;
        $memoryFound = null;

        if ($memory !== null) {
            $memoryFound = false;
        }
        foreach ($phones as $phone) {
            if ($phone->getActive()) {
                $anyActive = true;
            }
            if ($phone->shouldBeRetired()) {
                $anyRetired = true;
            }
            if ($phone->getCurrentMonthlyPhonePrice() || $phone->getCurrentYearlyPhonePrice()) {
                $anyPricing = true;
            }
            if ($memory !== null && $memory <= $phone->getMemory()) {
                $memoryFound = true;
            }
        }

        $deviceFound = count($phones) > 0 && $phones[0]->getMake() != "ALL";

        if (!$deviceFound || $memoryFound === false) {
            $this->unknownDevice($device, $memory);
        }

        if ($rooted) {
            $this->rootedDevice($device, $memory);
        }

        $differentMake = false;
        if ($deviceFound && !$phones[0]->isSameMake($make)) {
            $differentMake = true;
            if (!$ignoreMake) {
                $this->differentMake($phones[0], $make);
            }
        }

        return [
            'phones' => $phones,
            'deviceFound' => $deviceFound,
            'memoryFound' => $memoryFound,
            'differentMake' => $differentMake,
            'anyActive' => $anyActive,
            'anyRetired' => $anyRetired,
            'anyPricing' => $anyPricing,
        ];
    }

    /**
     * @param string $device
     * @param float  $memory
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device, $memory)
    {
        $searchDevice = (mb_substr($device, 0, 4) == 'iPad') ? 'iPad' : $device;
        if (in_array($searchDevice, [
            "", "generic_x86", "generic_x86_64", "Simulator", "iPad",
        ])) {
            return false;
        }
        $key = sprintf(self::REDIS_UNKNOWN_EMAIL_KEY_FORMAT, $device);
        if ($this->redis->get($key)) {
            return false;
        }
        $this->redis->setex($key, self::DUPLICATE_EMAIL_CACHE_TIME, 1);
        $playDeviceRepo = $this->dm->getRepository(PlayDevice::class);
        $playDevice = $playDeviceRepo->findOneBy(['device' => $device]);
        $marketingName = ($playDevice) ? $playDevice->getMarketingName() : 'unknown';
        $body = sprintf(
            'Unknown device queried: "%s" %s (%s GB). If device exists, memory may be higher than expected.',
            $marketingName,
            $device,
            $memory
        );

        $this->mailer->send(
            'Unknown Device/Memory',
            'analysis@so-sure.com',
            $body,
            null,
            null,
            'tech+ops@so-sure.com'
        );

        return true;
    }

    /**
     * @param Phone  $phone
     * @param string $phoneMake
     */
    private function differentMake(Phone $phone, $phoneMake)
    {
        if ($this->ignoreThisPhone($phone, $phoneMake)) {
            return;
        }

        $key = sprintf(self::REDIS_DIFFERENT_MAKE_EMAIL_KEY_FORMAT, $phone->getMake(), $phoneMake);
        if ($this->redis->get($key)) {
            return;
        }
        $this->redis->setex($key, self::DUPLICATE_EMAIL_CACHE_TIME, 1);

        $body = sprintf(
            'Make in db is different than phone make. Db: %s Phone: %s. DB details are enclosed: ObjectId("%s") %s',
            $phone->getMake(),
            $phoneMake,
            $phone->getId(),
            json_encode($phone->toApiArray())
        );

        $this->mailer->send('Make different in db', 'tech+ops@so-sure.com', $body);
    }

    private function ignoreThisPhone(Phone $phone, string $phoneMake): bool
    {
        // if we get more things we want to ignore, we'll get fancier.
        return $phone->getMake() === 'Huawei' && $phoneMake === 'HONOR';
    }

    /**
     * @param string $device
     * @param float  $memory
     */
    private function rootedDevice($device, $memory)
    {
        // add device-memory combinations to disable mail notifications
        if (in_array($device.'-'.$memory, ["bullhead-2"])) {
            return false;
        }

        $key = sprintf(self::REDIS_ROOTED_DEVICE_EMAIL_KEY_FORMAT, $device, $memory);
        if ($this->redis->get($key)) {
            return false;
        }

        $this->redis->setex($key, self::DUPLICATE_EMAIL_CACHE_TIME, 1);

        $body = sprintf(
            'Rooted device queried: %s (%s GB).',
            $device,
            $memory
        );

        $this->mailer->send(
            'Rooted Device/Memory',
            'tech+ops@so-sure.com',
            $body
        );
    }

    public function getMailer()
    {
        return $this->mailer;
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }
}
