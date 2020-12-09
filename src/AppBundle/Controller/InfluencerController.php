<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;

use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Influencer;
use AppBundle\Document\Form\CreateInfluencer;

use AppBundle\Form\Type\InfluencerType;

class InfluencerController extends BaseController
{
    /**
     * @Route("/influencer-signup", name="influencer_signup")
     */
    public function influencerAction(Request $request)
    {
        $create = new CreateInfluencer();
        $influencerForm = $this->get('form.factory')
            ->createNamedBuilder('influencerForm', InfluencerType::class, $create)
            ->getForm();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('influencerForm')) {
                    $dm = $this->getManager();
                    $influencerForm->handleRequest($request);
                    if ($influencerForm->isValid()) {
                        $userManager = $this->get('fos_user.user_manager');
                        $user = $userManager->createUser();
                        $user->setEnabled(true);
                        $influencerNumber = rand(1, 9999);
                        $user->setEmail(
                            'INF+' .
                            $create->getFirstName() .
                            $create->getLastName() .
                            $influencerNumber .
                            '@so-sure.net'
                        );
                        $user->setFirstName($create->getFirstName());
                        $user->setLastName($create->getLastName());
                        $user->setIsInfluencer(true);
                        $dm->persist($user);
                        $dm->flush();
                        $influencer = new Influencer();
                        $influencer->setUser($user);
                        $influencer->setEmail($create->getEmail());
                        $influencer->setOrganisation($create->getOrganisation());
                        $influencer->setGender($create->getGender());
                        $influencer->setType(Influencer::DEFAULT_TYPE);
                        $influencer->setTarget(Influencer::DEFAULT_TARGET);
                        $influencer->setDefaultValue(Influencer::DEFAULT_REWARD);
                        $expiryDate = new \DateTime('now +1year');
                        $influencer->setExpiryDate($expiryDate);
                        $influencer->setPolicyAgeMax(7);
                        $influencer->setUsageLimit(Influencer::LIMIT_USAGE);
                        $influencer->setHasNotClaimed(true);
                        $influencer->setHasRenewed(true);
                        $influencer->setHasCancelled(true);
                        $influencer->setIsFirst(true);
                        $influencer->setIsSignUpBonus(false);
                        $influencer->setIsConnectionBonus(false);
                        $dm->persist($influencer);
                        $scode = new SCode();
                        $scode->setType(SCode::TYPE_REWARD);
                        $scode->generateNamedCode($user, $influencerNumber);
                        $scode->setReward($influencer);
                        // @codingStandardsIgnoreStart
                        $influencer->setTermsAndConditions('<ol><li>Enter the code' . $scode->getCode() . 'into your customer dashboard within 7 days once you have bought your policy.</li><li>You will not be eligible for a voucher if the policy is cancelled or terminated within 60 days, or before the reward has been approved by so-sure.</li><li>The voucher will not be sent in conjunction with any other offer, cashback, reward or discount code unless listed on this page, or with any other discounts.</li><li>Once the above terms have been met, you will be sent an email to claim your voucher. If the voucher is unclaimed after 60 days from the date this email is sent, the claim will expire, and you will not receive your voucher.</li><li>Once you have claimed your voucher, you will be subject to the terms and conditions of the voucher provider. Please read these carefully once you have received your voucher.</li></ol>');
                        // @codingStandardsIgnoreEnd
                        $dm->persist($scode);
                        $dm->flush();

                        return $this->redirectToRoute('influencer_signup_complete_code', ['code' => $scode->getCode()]);
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to register influencer. %s',
                            (string) $influencerForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        $template = 'AppBundle:Influencer:signup.html.twig';
        $data = [
            'influencerForm' => $influencerForm->createView()
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/influencer-signup-complete/{code}", name="influencer_signup_complete_code")
     */
    public function influencerCompleteAction($code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Scode::class);
        $scode = null;

        try {
            if ($scode = $repo->findOneBy(['code' => $code, 'active' => true, 'type' => Scode::TYPE_REWARD])) {
                $reward = $scode->getReward();
                if (!$reward || !$reward->getUser() || !$reward->isOpen(new \DateTime())) {
                    throw new \Exception('Unknown promo code');
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        // Show user code/links - maybe even share icons
        $template = 'AppBundle:Influencer:signup-complete.html.twig';

        $data = [
            'scode' => $scode,
            'user' => $scode->getUser()
        ];

        return $this->render($template, $data);
    }
}
