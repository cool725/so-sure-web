<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param \Swift_Mailer   $mailer
     * @param                 $templating
     * @param                 $router
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        $templating,
        $router
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
    }

    public function addClaim(Policy $policy, Claim $claim)
    {
        $policy->addClaim($claim);
        $this->dm->flush();

        $this->processClaim($claim);
    }

    public function processClaim(Claim $claim)
    {
        if ($claim->getProcessed()) {
            return;
        }

        if ($claim->isMonetaryClaim()) {
            $claim->getPolicy()->updatePotValue();
            $this->dm->flush();
            $this->notifyMonetaryClaim($claim->getPolicy(), $claim, true);

            foreach ($claim->getPolicy()->getConnections() as $networkConnection) {
                $networkConnection->getPolicy()->updatePotValue();
                $this->dm->flush();
                $this->notifyMonetaryClaim($networkConnection->getPolicy(), $claim, false);
            }

            $claim->setProcessed(true);
            $this->dm->flush();
        }
    }

    public function notifyMonetaryClaim(Policy $policy, Claim $claim, $isClaimer)
    {
        try {
            $subject = sprintf(
                'Your friend %s has claimed on their so-sure policy',
                $claim->getPolicy()->getUser()->getName()
            );
            $templateHtml = "AppBundle:Email:claim/friend.html.twig";
            $templateText = "AppBundle:Email:claim/friend.txt.twig";
            if ($isClaimer) {
                $subject = sprintf(
                    "You've claimed on your so-sure policy",
                    $claim->getPolicy()->getUser()->getName()
                );
                $templateHtml = "AppBundle:Email:claim/self.html.twig";
                $templateText = "AppBundle:Email:claim/self.txt.twig";
            }

            $message = \Swift_Message::newInstance()
                ->setSubject($subject)
                ->setFrom('hello@so-sure.com')
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
}
