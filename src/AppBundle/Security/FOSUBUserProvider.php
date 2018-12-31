<?php
namespace AppBundle\Security;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Lead;
use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\Opt;
use AppBundle\Document\PhoneTrait;
use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;
use AppBundle\Service\MixpanelService;
use Doctrine\ODM\MongoDB\DocumentManager;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\FOSUBUserProvider as BaseClass;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Service\FacebookService;
use AppBundle\Service\GoogleService;
use AppBundle\Document\User;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use Symfony\Component\HttpFoundation\RequestStack;
use AppBundle\Validator\Constraints\UkMobileValidator;

class FOSUBUserProvider extends BaseClass
{
    use PhoneTrait;

    const SERVICE_ACCOUNTKIT = 'accountkit';

    /** @var RequestStack */
    protected $requestStack;

    protected $encoderFactory;

    protected $authService;

    protected $facebook;

    protected $google;

    /** @var IntercomService */
    protected $intercom;

    /** @var MixpanelService */
    protected $mixpanel;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailerService */
    protected $mailer;

    public function setRequestStack(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function setEncoderFactory($encoderFactory)
    {
        $this->encoderFactory = $encoderFactory;
    }

    public function setAuthService($authService)
    {
        $this->authService = $authService;
    }

    /**
     * @param DocumentManager $dm
     */
    public function setDm(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function setIntercom(IntercomService $intercom)
    {
        $this->intercom = $intercom;
    }

    public function setMixpanel(MixpanelService $mixpanel)
    {
        $this->mixpanel = $mixpanel;
    }

    public function setMailer(MailerService $mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * {@inheritDoc}
     */
    public function connect(UserInterface $user, UserResponseInterface $response)
    {
        $property = $this->getProperty($response);
        $username = $response->getUsername();

        //on connect - get the access token and the user ID
        $service = $response->getResourceOwner()->getName();

        $setter = 'set'.ucfirst($service);
        $setter_id = $setter.'Id';
        $setter_token = $setter.'AccessToken';

        //we "disconnect" previously connected users
        /** @var \FOS\UserBundle\Model\UserInterface $previousUser */
        $previousUser = $this->userManager->findUserBy(array($property => $username));
        if ($previousUser) {
            /** @var UserInterface $previousUser */
            $previousUser->$setter_id(null);
            $previousUser->$setter_token(null);
            $this->userManager->updateUser($previousUser);
        }

        //we connect current user
        $user->$setter_id($username);
        $user->$setter_token($this->getLongLivedAccessToken($response));

        /** @var \FOS\UserBundle\Model\UserInterface $fosUser */
        $fosUser = $user;
        $this->userManager->updateUser($fosUser);
    }

    /**
     * @param UserResponseInterface $response
     * @return User|\FOS\UserBundle\Model\UserInterface|UserInterface|null
     * @throws \Exception
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $service = $response->getResourceOwner()->getName();
        $username = $response->getUsername();
        $search = $this->getProperty($response);
        if ($service == self::SERVICE_ACCOUNTKIT) {
            $search = 'mobileNumber';
            if (isset($response->getResponse()['phone'])) {
                $username = $response->getResponse()['phone']['number'];
            }
        }

        #if ($search == "facebook_id") {
        #    $search = "facebookId";
        #}
        if (!$username || !$search || mb_strlen(trim($username)) == 0 || mb_strlen(trim($search)) == 0) {
            // if username or search is empty, it could return the first in the db
            throw new \Exception(sprintf(
                'Unable to detect search %s',
                json_encode($response->getResponse())
            ));
        }
        $user = $this->userManager->findUserBy(array($search => $username));
        //when the user is registrating
        if (null == $user) {
            // Not guarenteed an email address
            if (!$response->getEmail()) {
                if ($service != self::SERVICE_ACCOUNTKIT) {
                    $msg = sprintf('Unable to find an account matching your %s details.', $service);
                } else {
                    $msg = sprintf('Unable to find an account with the mobile number provided.');
                }

                if ($request = $this->requestStack->getCurrentRequest()) {
                    if ($session = $request->getSession()) {
                        if ($session->isStarted()) {
                            /** @var Session $actualSession */
                            $actualSession = $session;
                            /** @var FlashBag $flashbag */
                            $flashbag = $actualSession->getFlashBag();
                            $flashbag->add('error', $msg);
                        }
                    }
                }

                return null;
            }

            if ($this->userManager->findUserBy(['emailCanonical' => mb_strtolower($response->getEmail())])) {
                $msg = 'You appear to already have an account, but its not connected. Please login and connect.';

                if ($request = $this->requestStack->getCurrentRequest()) {
                    if ($session = $request->getSession()) {
                        if ($session->isStarted()) {
                            /** @var Session $actualSession */
                            $actualSession = $session;
                            /** @var FlashBag $flashbag */
                            $flashbag = $actualSession->getFlashBag();
                            $flashbag->add('error', $msg);
                        }
                    }
                }

                throw new AccountNotLinkedException($msg);
            }

            // create new user here
            /** @var User $user */
            $user = $this->userManager->createUser();

            if ($service != self::SERVICE_ACCOUNTKIT) {
                $setter = 'set'.ucfirst($service);
                $setter_id = $setter.'Id';
                $setter_token = $setter.'AccessToken';
                $user->$setter_id($username);
                $user->$setter_token($this->getLongLivedAccessToken($response));
            }

            $user->setEmail($response->getEmail());
            $user->setFirstName($this->conformAlphanumeric(explode(' ', $response->getFirstName())[0], 50));
            $user->setLastName($this->conformAlphanumeric(explode(' ', $response->getLastName())[0], 50));

            // Starling
            if ($service == 'starling') {
                if (isset($response->getResponse()['dateOfBirth'])) {
                    $birthday = \DateTime::createFromFormat('Y-m-d', $response->getResponse()['dateOfBirth']);
                    $birthday = $birthday->setTime(0, 0, 0);
                    $user->setBirthday($birthday);
                }
                if (isset($response->getResponse()['phone'])) {
                    $validator = new UkMobileValidator();
                    $user->setMobileNumber($validator->conform($response->getResponse()['phone']));
                }
            }

            $user->setEnabled(true);

            $this->userManager->updateUser($user);
            return $user;
        }

        //if user exists - go with the HWIOAuth way
        //$user = parent::loadUserByOAuthUserResponse($response);
        if ($service != self::SERVICE_ACCOUNTKIT) {
            $setter = 'set' . ucfirst($service) . 'AccessToken';
            //update access token
            $user->$setter($this->getLongLivedAccessToken($response));
        }

        return $user;
    }

    /**
     * @param FacebookService $facebook
     */
    public function setFacebook(FacebookService $facebook)
    {
        $this->facebook = $facebook;
    }

    /**
     * @param GoogleService $google
     */
    public function setGoogle(GoogleService $google)
    {
        $this->google = $google;
    }

    protected function conformAlphanumeric($value, $length)
    {
        $validator = new AlphanumericValidator();

        return $validator->conform(mb_substr($value, 0, $length));
    }

    private function getLongLivedAccessToken(UserResponseInterface $response)
    {
        $service = $response->getResourceOwner()->getName();
        if ($service != 'Facebook' || !$this->facebook) {
            return $response->getAccessToken();
        }

        $fb = $this->facebook->initToken($response->getAccessToken());
        $fb->setExtendedAccessToken(); //long-live access_token 60 days

        return $fb->getAccessToken();
    }

    public function isHistoricalPassword($user, $plainPassword, $oldPassword, $oldSalt)
    {
        $password = $this->getPassword($user, $plainPassword, $oldSalt);

        $same = $oldPassword === $password;
        // @codingStandardsIgnoreStart
        // print sprintf('Checking %s %d (Salt: %s) %s ?= %s%s', $plainPassword, $same, $oldSalt, $oldPassword, $password, PHP_EOL);
        // @codingStandardsIgnoreEnd

        return $same;
    }

    public function getPassword(User $user, $plainPassword, $salt)
    {
        $encoder = $this->encoderFactory->getEncoder($user);

        return $encoder->encodePassword($plainPassword, $salt);
    }

    public function previousPasswordCheck(User $user)
    {
        $employee = true;
        $claims = true;
        try {
            // non url requests will not have firewall setup
            $employee = $this->authService->isGranted(User::ROLE_EMPLOYEE, $user);
        } catch (\Exception $e) {
            // fallback to check for known roles
            $employee = $user->hasEmployeeRole();
        }
        try {
            // non url requests will not have firewall setup
            $claims = $this->authService->isGranted(User::ROLE_CLAIMS, $user);
        } catch (\Exception $e) {
            // fallback to check for known roles
            $employee = $user->hasClaimsRole();
        }

        if ($employee || $claims) {
            // PCI Requirement - 4 passwords
            $user->setPreviousPasswordCheck(!$this->hasPreviouslyUsedPassword($user, 4));
        } else {
            // For users make it simple
            $user->setPreviousPasswordCheck(!$this->hasPreviouslyUsedPassword($user, 2));
        }

        return $user->getPreviousPasswordCheck();
    }

    public function hasPreviouslyUsedPassword(User $user, $attempts = null)
    {
        $oldPasswords = $user->getPreviousPasswords();

        if (!is_array($oldPasswords)) {
            $oldPasswords = $user->getPreviousPasswords()->getValues();
        }
        if (count($oldPasswords) == 0) {
            return false;
        }
        $plainPassword = $user->getPlainPassword();

        // current password not allowed
        if ($this->isHistoricalPassword($user, $plainPassword, $user->getPassword(), $user->getSalt())) {
            return true;
        }

        krsort($oldPasswords);
        $count = 1;
        foreach ($oldPasswords as $timestamp => $passwordData) {
            if ($attempts && $count >= $attempts) {
                break;
            }

            if ($this->isHistoricalPassword($user, $plainPassword, $passwordData['password'], $passwordData['salt'])) {
                return true;
            }

            $count++;
        }

        return false;
    }

    /**
     * @param User   $user
     * @param string $email
     * @param string $mobile
     * @param string $facebookId
     * @param string $googleId
     * @return bool True if resolved; false if unable to resolve (e.g. user must login as policy exists)
     */
    public function resolveDuplicateUsers(
        User $user = null,
        $email = null,
        $mobile = null,
        $facebookId = null,
        $googleId = null
    ) {
        /** @var \AppBundle\Repository\UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $users = $userRepo->getDuplicateUsers(
            $user,
            $email,
            $facebookId,
            $mobile,
            $googleId
        );
        if (!$users || count($users) == 0) {
            return true;
        }
        $email = mb_strtolower($email);
        $mobile = $this->normalizeUkMobile($mobile);

        foreach ($users as $duplicate) {
            /** @var User $duplicate */
            // any user who has a non-partial policy can not be changed
            if (count($duplicate->getCreatedPolicies()) > 0) {
                // Go ahead and continue here rather than return false
                // There may be many associated accounts with duplicate info and so clearing/deleting
                // all duplicates, may help in some cases
                continue;
            }

            // don't delete so-sure accounts
            if ($duplicate->hasSoSureEmail() || $duplicate->hasSoSureRewardsEmail()) {
                continue;
            }

            // One duplicate may match multiple items
            if ($duplicate->getMobileNumber() == $mobile) {
                $duplicate->setMobileNumber(null);
            }
            if ($duplicate->getFacebookId() == $facebookId) {
                $duplicate->setFacebookId(null);
                $duplicate->setFacebookAccessToken(null);
            }
            if ($duplicate->getGoogleId() == $googleId) {
                $duplicate->setGoogleId(null);
                $duplicate->setGoogleAccessToken(null);
            }
            // as username is tied to email for our case, delete the duplicate user
            if ($duplicate->getEmailCanonical() == $email) {
                $this->deleteUser($duplicate, false);
            }
        }
        $this->dm->flush();
        $this->dm->clear();
        if ($userRepo->existsAnotherUser(
            $user,
            $email,
            $facebookId,
            $mobile,
            $googleId
        )) {
            return false;
        }

        return true;
    }

    public function deleteUser(User $user, $sendEmail = true, $flush = false)
    {
        if (!$user->canDelete()) {
            throw new \Exception(sprintf('Unable to delete user %s due to rentention rules', $user->getId()));
        }
        if ($user->getIntercomId()) {
            $this->intercom->queueUser($user, IntercomService::QUEUE_USER_DELETE, [
                'intercomId' => $user->getIntercomId()
            ]);
        }
        $this->mixpanel->queueDelete($user->getId());

        if ($user->hasPartialPolicy()) {
            foreach ($user->getPartialPolicies() as $partialPolicy) {
                $this->dm->remove($partialPolicy);
            }
        }
        if ($user->getReceivedInvitations() && count($user->getReceivedInvitations()) > 0) {
            foreach ($user->getReceivedInvitations() as $invitation) {
                /** @var Invitation $invitation */
                $invitation->setInvitee(null);
            }
            $user->setReceivedInvitations(null);
        }

        $this->deleteOpts($user->getEmailCanonical());

        if ($flush) {
            $this->dm->flush();
        }

        if ($sendEmail) {
            $this->mailer->sendTemplateToUser(
                'Goodbye',
                $user,
                'AppBundle:Email:user/deleted.html.twig',
                ['user' => $user],
                'AppBundle:Email:user/deleted.html.twig',
                ['user' => $user]
            );
        }

        $this->dm->remove($user);

        if ($flush) {
            $this->dm->flush();
        }
    }

    private function deleteOpts($email)
    {
        $opt = $this->dm->getRepository(Opt::class);
        $opts = $opt->findBy(['email' => mb_strtolower($email)]);
        foreach ($opts as $opt) {
            $this->dm->remove($opt);
        }
    }

    public function deleteLead(Lead $lead, $flush = false)
    {
        if ($lead->getIntercomId()) {
            $this->intercom->queueLead($lead, IntercomService::QUEUE_LEAD_DELETE, [
                'intercomId' => $lead->getIntercomId()
            ]);
        }

        if ($lead->getEmailCanonical()) {
            $userRepo = $this->dm->getRepository(User::class);
            $user = $userRepo->findOneBy(['emailCanonical' => $lead->getEmailCanonical()]);
            if (!$user) {
                $this->deleteOpts($lead->getEmailCanonical());
            }
        }

        $this->dm->remove($lead);

        if ($flush) {
            $this->dm->flush();
        }
    }

    public function resyncOpts()
    {
        $userRepo = $this->dm->getRepository(User::class);
        $optinRepo = $this->dm->getRepository(EmailOptIn::class);
        $optoutRepo = $this->dm->getRepository(EmailOptOut::class);

        $optins = $optinRepo->findBy(['user' => null]);
        foreach ($optins as $optin) {
            /** @var EmailOptIn $optin */
            /** @var User $user */
            $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($optin->getEmail())]);
            if ($user) {
                $user->addOpt($optin);
            }
        }

        $optouts = $optoutRepo->findBy(['user' => null]);
        foreach ($optouts as $optout) {
            /** @var EmailOptOut $optout */
            /** @var User $user */
            $user = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($optout->getEmail())]);
            if ($user) {
                $user->addOpt($optout);
            }
        }

        $this->dm->flush();
    }
}
