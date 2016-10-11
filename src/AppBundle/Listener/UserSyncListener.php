<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Service\IntercomService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class UserSyncListener
{
    /** @var IntercomService */
    protected $intercom;

    /**
     * @param IntercomService $intercom
     */
    public function __construct(
        IntercomService $intercom
    ) {
        $this->intercom = $intercom;
    }

    // TODO: New Method for listening for email change event
    // TODO: Test class
    // TODO: Trigger event on any connection change (different listener/event probably)
    // TODO: Trigger event on any claim change (different listener/event probably)
    // TODO: Or instead, add listener on policy record for anything that changes amounts...
    // TODO: Start thinking about sending events to intercom as well
    // TODO: Test lead conversion

    public function onIntercomSyncEvent(User $user)
    {
        $this->intercom->queue($user);
    }
}
