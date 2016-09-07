<?php
namespace AppBundle\Service;

use AppBundle\Document\User;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\ClientUrl;

class PushService
{
    const DISPLAY_POPUP = 'popup';
    const DISPLAY_NONE = 'none';
    const DISPLAY_INLINE = 'inline';

    const MESSAGE_GENERAL = 'general';

    // sent to inviter
    const MESSAGE_CONNECTED = 'connected';

    // sent to invitee
    const MESSAGE_INVITATION = 'invitation';

    // these are currently mixpanel events
    const MESSAGE_RATEMYAPP = 'ratemyapp';
    const MESSAGE_PROMO = 'promo';

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
        $this->sns->publish([
           'TargetArn' => $arn,
           'MessageStructure' => 'json',
            'Message' => json_encode([
                'APNS' => json_encode($this->generateAPNSMessage($messageType, $message, $badge)),
                'APNS_SANDBOX' => json_encode($this->generateAPNSMessage($messageType, $message, $badge)),
                'GCM' => json_encode($this->generateGCMMessage($messageType, $message)),
            ])
        ]);
    }

    public function getUri($messageType)
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

    public function getDisplay($messageType)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return self::DISPLAY_POPUP;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return self::DISPLAY_POPUP;
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return self::DISPLAY_POPUP;
        } else {
            return null;
        }
    }

    public function getRefresh($messageType)
    {
        if ($messageType == self::MESSAGE_GENERAL) {
            return null;
        } elseif ($messageType == self::MESSAGE_CONNECTED) {
            return true;
        } elseif ($messageType == self::MESSAGE_INVITATION) {
            return true;
        } else {
            return null;
        }
    }

    /**
     * @see https://developers.google.com/cloud-messaging/http-server-ref#notification-payload-support
     */
    public function generateGCMMessage($messageType, $message)
    {
        $gcm['data']['message'] = $message;

        $gcm['data'] = array_merge($gcm['data'], $this->getCustomData($messageType));

        return $gcm;
    }

    /**
     * @codingStandardsIgnoreStart
     * @see https://developer.apple.com/library/ios/documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/Chapters/TheNotificationPayload.html
     * @codingStandardsIgnoreEnd
     */
    public function generateAPNSMessage($messageType, $message, $badge = null, $newContent = null)
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
            $apns['aps']['content-available'] = 1;
        }

        // custom data
        $apns = array_merge($apns, $this->getCustomData($messageType));

        return $apns;
    }

    public function getCustomData($messageType)
    {
        $data = [];
        $uri = $this->getUri($messageType);
        if ($uri) {
            $data['ss']['uri'] = $uri;
        }
        $display = $this->getDisplay($messageType);
        if ($display) {
            $data['ss']['display'] = $display;
        }
        $refresh = $this->getRefresh($messageType);
        if ($refresh) {
            $data['ss']['refresh'] = $refresh;
        }

        // Depreciated field, but keep as alert to always display message
        $data['type'] = 'alert';

        return $data;
    }
}
