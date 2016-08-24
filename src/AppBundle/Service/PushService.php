<?php
namespace AppBundle\Service;

use AppBundle\Document\User;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\ClientUrl;

class PushService
{
    const MESSAGE_GENERAL = 'general';

    // sent to inviter
    const MESSAGE_CONNECTED = 'connected';

    // sent to invitee
    const MESSAGE_INVITATION = 'invitation';

    /** @var LoggerInterface */
    protected $logger;

    protected $sns;

    /**
     * @param LoggerInterface $logger
     * @param                 $sns
     */
    public function __construct(
        LoggerInterface $logger,
        $sns
    ) {
        $this->logger = $logger;
        $this->sns = $sns;
    }

    public function sendToUser($messageType, User $user, $message, $badge = null)
    {
        $this->logger->debug(sprintf('Push triggered to user id: %s %s', $user->getId(), $message));
        if (!$user->getSnsEndpoint() || strlen(trim($user->getSnsEndpoint())) == 0) {
            $this->logger->debug(sprintf('Push skipped (no endpoint)'));

            return;
        }

        return $this->send($messageType, $user->getSnsEndpoint(), $message, $badge);
    }

    public function send($messageType, $arn, $message, $badge = null)
    {
        $this->logger->debug(sprintf('Push triggered to %s %s', $arn, $message));
        $url = $this->getUrl($messageType);
        $this->sns->publish([
           'TargetArn' => $arn,
           'MessageStructure' => 'json',
            'Message' => json_encode([
                'APNS' => json_encode($this->generateAPNSMessage($message, $url, $badge)),
                'APNS_SANDBOX' => json_encode($this->generateAPNSMessage($message, $url, $badge)),
                'GCM' => json_encode($this->generateGCMMessage($message, $url)),
            ])
        ]);
    }

    public function getUrl($messageType)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return null;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return ClientUrl::POT;
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return ClientUrl::POT;
        } else {
            return null;
        }
    }

    /**
     * @see https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
     */
    private function generateGCMMessage($message, $url = null)
    {
        $gcm['data']['message'] = $message;

        // Currently required due to https://github.com/so-sure/product-backlog/wiki/Push-Notification-Rules
        // Proposed ticket to remove https://app.clubhouse.io/sosure/story/210/change-to-push-notification-rules
        $gcm['data']['type'] = 'alert';

        if ($url) {
            $gcm['data']['url'] = $url;
        }

        return $gcm;
    }

    /**
     * @codingStandardsIgnoreStart
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html
     * @codingStandardsIgnoreEnd
     */
    private function generateAPNSMessage($message, $url = null, $badge = null, $newContent = null)
    {
        if ($badge && $newContent) {
            throw new \Exception('Silent notifications can not contain badge updates');
        }

        $apns['aps']['alert'] = $message;
        /*
        if ($category) {
            // Decide on category types with ios
            $apns['aps']['category'] = $category;
        }
        */
        if ($badge) {
            $apns['aps']['badge'] = $badge;
        }
        if ($newContent) {
            $apns['apps']['content-available'] = 1;
        }

        // custom data
        if ($url) {
            $apns['url'] = $url;
        }

        // Currently required due to https://github.com/so-sure/product-backlog/wiki/Push-Notification-Rules
        // Proposed ticket to remove https://app.clubhouse.io/sosure/story/210/change-to-push-notification-rules
        $apns['type'] = 'alert';

        return $apns;
    }
}
