<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class PushService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    protected $sns;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param                  $sns
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $sns
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sns = $sns;
    }

    public function sendToUser(User $user, $message)
    {
        if (!$user->getSnsEndpoint() || strlen(trim($user->getSnsEndpoint())) == 0) {
            return;
        }

        return $this->send($user->getSnsEndpoint(), $message);
    }

    public function send($arn, $message)
    {
        $this->sns->publish([
           'TargetArn' => $arn,
           'MessageStructure' => 'json',
            'Message' => json_encode([
                'APNS' => json_encode($this->generateAPNSMessage($message)),
                'APNS_SANDBOX' => json_encode($this->generateAPNSMessage($message)),
                'GCM' => json_encode($this->generateGCMMessage($message)),
            ])
        ]);
    }

    /**
     * @see https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
     */
    private function generateGCMMessage($message)
    {
        $gcm = [
            'data' => ['message' => $message],
        ];

        return $gcm;
    }

    /**
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html
     */
    private function generateAPNSMessage($message, $category = null, $badge = null, $newContent = null)
    {
        if ($badge && $newContent) {
            throw new \Exception('Silent notifications can not contain badge updates');
        }

        $apns['aps']['alert'] = $message;
        if ($category) {
            // Decide on category types with ios
            $apns['aps']['category'] = $category;
        }
        if ($badge) {
            $apns['aps']['badge'] = $badge;
        }
        if ($newContent) {
            $apns['apps']['content-available'] = 1;  
        }

        return $apns;
    }
}
