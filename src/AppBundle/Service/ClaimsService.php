<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\LostPhone;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class ClaimsService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var \Swift_Mailer */
    protected $mailer;
    protected $templating;
    protected $router;

    /** @var string */
    protected $environment;

    /** @var string */
    protected $defaultSenderAddress;

    /** @var string */
    protected $defaultSenderName;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param \Swift_Mailer   $mailer
     * @param                 $templating
     * @param                 $router
     * @param string          $environment
     * @param string          $defaultSenderAddress
     * @param string          $defaultSenderName
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        $environment,
        $defaultSenderAddress,
        $defaultSenderName
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->environment = $environment;
        $this->defaultSenderAddress = $defaultSenderAddress;
        $this->defaultSenderName = $defaultSenderName;
    }

    public function addClaim(Policy $policy, Claim $claim)
    {
        $repo = $this->dm->getRepository(Claim::class);

        // Claim state for same claim number may change
        // (not yet sure if we want a new claim record vs update claim record)
        // Regardless, same claim number for different policies is not allowed
        // Also same claim number on same policy with same state is not allowed
        $duplicates = $repo->findBy(['number' => (string) $claim->getNumber()]);
        foreach ($duplicates as $duplicate) {
            if ($policy->getId() != $duplicate->getPolicy()->getId()) {
                return false;
            }
            if ($claim->getStatus() == $duplicate->getStatus()) {
                return false;
            }
        }

        $policy->addClaim($claim);
        $this->dm->flush();

        $this->processClaim($claim);
        if ($claim->getShouldCancelPolicy()) {
            $this->notifyPolicyShouldBeCancelled($policy, $claim);
        }

        return true;
    }

    public function processClaim(Claim $claim)
    {
        if ($claim->getProcessed()) {
            return;
        }

        if ($claim->isMonetaryClaim()) {
            if (!$claim->getPolicy() instanceof PhonePolicy) {
                throw new \Exception('not policy');
            }
            $claim->getPolicy()->updatePotValue();
            $this->dm->flush();
            $this->notifyMonetaryClaim($claim->getPolicy(), $claim, true);

            foreach ($claim->getPolicy()->getConnections() as $networkConnection) {
                $networkConnection->getLinkedPolicy()->updatePotValue();
                $this->dm->flush();
                $this->notifyMonetaryClaim($networkConnection->getLinkedPolicy(), $claim, false);
            }

            $claim->setProcessed(true);
            $this->recordLostPhone($claim->getPolicy(), $claim);
            $this->dm->flush();
        }
    }

    public function recordLostPhone(Policy $policy, Claim $claim)
    {
        if (!$claim->isOwnershipTransferClaim()) {
            return;
        }

        // Check if phone has been 'lost' multiple times
        $repo = $this->dm->getRepository(LostPhone::class);
        $lost = $repo->findOneBy(['imei' => $policy->getImei()]);
        if ($lost) {
            $this->logger->error(sprintf(
                'Imei (%s) that was previously reported as lost is being reported as lost again.',
                $policy->getImei()
            ));
        }

        $lost = new LostPhone();
        $lost->populate($policy);
        $this->dm->persist($lost);
        $this->dm->flush();

        return $lost;
    }

    public function notifyMonetaryClaim(Policy $policy, Claim $claim, $isClaimer)
    {
        try {
            $subject = sprintf(
                'Your friend, %s, has made a claim.',
                $claim->getPolicy()->getUser()->getName()
            );
            $templateHtml = "AppBundle:Email:claim/friend.html.twig";
            $templateText = "AppBundle:Email:claim/friend.txt.twig";
            if ($isClaimer) {
                $subject = sprintf(
                    "Sorry to hear something happened to your phone. We hope you're okay."
                );
                $templateHtml = "AppBundle:Email:claim/self.html.twig";
                $templateText = "AppBundle:Email:claim/self.txt.twig";
            }

            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
                ->setTo($policy->getUser()->getEmail())
                ->setBody(
                    $this->templating->render($templateHtml, ['claim' => $claim, 'policy' => $policy]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render($templateHtml, ['claim' => $claim, 'policy' => $policy]),
                    'text/plain'
                );
            $this->mailer->send($message);
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Error in notifyMonetaryClaim. Ex: %s", $e->getMessage()));
        }
    }

    public function notifyPolicyShouldBeCancelled(Policy $policy, Claim $claim)
    {
        try {
            $subject = sprintf(
                'Policy %s should be cancelled',
                $claim->getPolicy()->getPolicyNumber()
            );
            if ($this->environment != 'prod') {
                $subject = sprintf('[%s] %s', $this->environment, $subject);
            }
            $templateHtml = "AppBundle:Email:claim/shouldBeCancelled.html.twig";

            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
                ->setTo('support@wearesosure.com')
                ->setBody(
                    $this->templating->render($templateHtml, ['claim' => $claim, 'policy' => $policy]),
                    'text/html'
                );
            $this->mailer->send($message);
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Error in notifyPolicyShouldBeCancelled.", ['exception' => $e]));
        }
    }
}
