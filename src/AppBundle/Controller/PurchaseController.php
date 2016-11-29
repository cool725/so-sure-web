<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Session\Session;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\User;
use AppBundle\Document\Form\Purchase;
use AppBundle\Document\Form\PurchaseStepPersonal;
use AppBundle\Document\Form\PurchaseStepAddress;
use AppBundle\Document\Form\PurchaseStepPhone;

use AppBundle\Form\Type\BasicUserType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\PurchaseType;
use AppBundle\Form\Type\PurchaseStepPersonalType;
use AppBundle\Form\Type\PurchaseStepAddressType;
use AppBundle\Form\Type\PurchaseStepPhoneType;

use AppBundle\Security\UserVoter;

use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\RateLimitException;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    /**
     * @Route("/phone/{phoneId}", name="purchase_phone", requirements={"phoneId":"[0-9a-f]{24,24}"})
     * @Route("/{policyId}", name="purchase_policy", requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Route("/", name="purchase")
     * @Template
    */
    public function purchaseAction(Request $request, $phoneId = null, $policyId = null)
    {
        $user = $this->getUser();
        if (!$user) {
            throw new AccessDeniedException('User must be logged in to purchase a policy');
        }
        if ($user->hasValidPolicy()) {
            $this->addFlash(
                'warning',
                'Sorry, we currently only support 1 policy per user.  Multiple policies are coming soon!'
            );

            return $this->redirectToRoute('user_home');
        }

        $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);

        $webpay = null;
        $session = $request->getSession();
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $policyRepo = $dm->getRepository(PhonePolicy::class);
        $policy = null;
        $phone = null;
        if ($phoneId) {
            $phone = $phoneRepo->find($phoneId);
        } elseif ($policyId) {
            $policy = $policyRepo->find($policyId);
            if (!$policy) {
                throw $this->createNotFoundException('Policy not found');
            }

            if ($policy->isValidPolicy()) {
                $this->addFlash('warning', 'This policy was already purchased.');
                return $this->redirectToRoute('user_home');
            }
            $phone = $policy->getPhone();
        } elseif ($session->get('quote')) {
            $phone = $phoneRepo->find($session->get('quote'));
        } else {
            throw $this->createNotFoundException('Missing argument');
        }
        if (!$phone) {
            throw $this->createNotFoundException('Phone not found');
        }

        // If there are any policies in progress, redirect to the purchase route
        if (!$policy) {
            $initPolicies = $user->getInitPolicies();
            if (count($initPolicies) > 0) {
                return $this->redirectToRoute('purchase_policy', ['policyId' => $initPolicies[0]->getId()]);
            }
        }

        $purchase = new Purchase();
        $purchase->populateFromUser($user);
        $purchase->setPhone($phone);
        if ($policy) {
            $purchase->setImei($policy->getImei());
            $purchase->setSerialNumber($policy->getSerialNumber());
        }
        if ($request->get('email')) {
            $purchase->setEmail($request->get('email'));
        }
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    $userRepo = $dm->getRepository(User::class);
                    $userExists = $userRepo->existsAnotherUser(
                        $user,
                        $purchase->getEmail(),
                        null,
                        $purchase->getMobileNumber()
                    );
                    if ($userExists) {
                        // @codingStandardsIgnoreStart
                        $err = 'It looks like you already have an account.  Please logout and try logging in with a different email/mobile number';
                        // @codingStandardsIgnoreEnd
                        $this->addFlash('error', $err);

                        // TODO: would be good to auto logout.  redirecting to /logout doesn't work well
                        throw new \Exception($err);
                    }

                    $purchase->populateUser($user);

                    if (!$user->hasValidDetails() || !$user->hasValidBillingDetails()) {
                        $this->get('logger')->error(sprintf(
                            'Invalid purchase user details %s',
                            json_encode($purchase->toApiArray())
                        ));
                        throw new \InvalidArgumentException(sprintf(
                            'User is missing details such as name, email address, or billing details (User: %s)',
                            $user->getId()
                        ));
                    }
                    if ($policy) {
                        // If any policy data has changed, delete/re-create
                        if ($policy->getImei() != $purchase->getImei() ||
                            $policy->getSerialNumber() != $purchase->getSerialNumber() ||
                            $policy->getPhone()->getId() != $purchase->getPhone()->getId()) {
                            $dm->remove($policy);
                            $dm->flush();
                            $policy = null;
                        }
                    }

                    $allowPayment = true;
                    if (!$policy) {
                        try {
                            $policyService = $this->get('app.policy');
                            $policy = $policyService->init(
                                $user,
                                $phone,
                                $purchase->getImei(),
                                $purchase->getSerialNumber(),
                                $this->getIdentityLogWeb($request)
                            );
                            $dm->persist($policy);
                        } catch (InvalidPremiumException $e) {
                            // Nothing the user can do, so rethow
                            throw $e;
                        } catch (InvalidUserDetailsException $e) {
                            $this->addFlash(
                                'error',
                                "Please check all your details.  It looks like we're missing something."
                            );
                            $allowPayment = false;
                        } catch (GeoRestrictedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you. It looks like you're outside the UK."
                            );
                            throw $this->createNotFoundException('Unable to see policy');
                        } catch (DuplicateImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (LostStolenImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (ImeiBlacklistedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        } catch (InvalidImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Looks like the IMEI you provided isn't quite right.  Please check the number again."
                            );
                            $allowPayment = false;
                        } catch (ImeiPhoneMismatchException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        } catch (RateLimitException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        }
                    }
                    $dm->flush();

                    if ($allowPayment) {
                        $webpay = $this->get('app.judopay')->webpay(
                            $policy,
                            $purchase->getAmount(),
                            $request->getClientIp(),
                            $request->headers->get('User-Agent')
                        );
                    }
                }
            }
        }

        $data = array(
            'phone' => $phone,
            'purchase_form' => $purchaseForm->createView(),
            'policy_key' => $this->getParameter('policy_key'),
            'is_postback' => 'POST' === $request->getMethod(),
        );

        if (isset($webpay['post_url']) && isset($webpay['payment'])) {
            $data['webpay_action'] = $webpay['post_url'];
            $data['webpay_reference'] = $webpay['payment']->getReference();
        }
        
        return $data;
    }

    /**
     * @Route("/step-personal", name="purchase_step_personal")
     * @Template
    */
    public function purchaseStepPersonalAction(Request $request)
    {
        $user = $this->getUser();
        /*
        if ($user->getFirstName() && $user->getLastName() && $user->getMobileNumber() && $user->getBirthday()) {
            return $this->redirectToRoute('purchase_step_2');
        }
        */
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        $dm = $this->getManager();

        $purchase = new PurchaseStepPersonal();
        $purchase->populateFromUser($user);
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPersonalType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    $userRepo = $dm->getRepository(User::class);
                    $userExists = $userRepo->existsAnotherUser(
                        $user,
                        $purchase->getEmail(),
                        null,
                        $purchase->getMobileNumber()
                    );
                    if ($userExists) {
                        // @codingStandardsIgnoreStart
                        $err = 'It looks like you already have an account.  Please logout and try logging in with a different email/mobile number';
                        // @codingStandardsIgnoreEnd
                        $this->addFlash('error', $err);

                        // TODO: would be good to auto logout.  redirecting to /logout doesn't work well
                        throw new \Exception($err);
                    }

                    $purchase->populateUser($user);

                    if (!$user->hasValidDetails()) {
                        $this->get('logger')->error(sprintf(
                            'Invalid purchase user details %s',
                            json_encode($purchase->toApiArray())
                        ));
                        throw new \InvalidArgumentException(sprintf(
                            'User is missing details such as name, email address, or birthday (User: %s)',
                            $user->getId()
                        ));
                    }
                    $dm->flush();

                    return $this->redirectToRoute('purchase_step_address');
                }
            }
        }

        $data = array(
            'purchase_form' => $purchaseForm->createView(),
            'step' => 1,
        );

        return $data;
    }
    
    /**
     * @Route("/step-address", name="purchase_step_address")
     * @Template
    */
    public function purchaseStepAddressAction(Request $request)
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);
        if (!$user->hasValidDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();

        $purchase = new PurchaseStepAddress();
        $purchase->populateFromUser($user);
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepAddressType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    $purchase->populateUser($user);

                    if (!$user->hasValidBillingDetails()) {
                        $this->get('logger')->error(sprintf(
                            'Invalid purchase user billing details %s',
                            json_encode($purchase->toApiArray())
                        ));
                        throw new \InvalidArgumentException(sprintf(
                            'User is missing billing details (User: %s)',
                            $user->getId()
                        ));
                    }
                    $dm->flush();

                    return $this->redirectToRoute('purchase_step_phone');
                }
            }
        }

        $data = array(
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 2,
        );

        return $data;
    }

    /**
     * @Route("/step-phone", name="purchase_step_phone")
     * @Template
    */
    public function purchaseStepPhoneAction(Request $request)
    {
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);
        if (!$user->hasValidBillingDetails()) {
            return $this->redirectToRoute('purchase_step_address');
        }

        $session = $request->getSession();
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);

        $phone = null;
        if ($session->get('quote')) {
            $phone = $phoneRepo->find($session->get('quote'));
        }
        if (!$phone) {
            // TODO: display phone selector
            throw new \Exception('Unknown phone');
        }

        $purchase = new PurchaseStepPhone();
        $purchase->setPhone($phone);
        $policy = $user->getUnInitPolicy();
        if ($policy) {
            $purchase->setImei($policy->getImei());
            $purchase->setSerialNumber($policy->getSerialNumber());
        }
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    if ($policy) {
                        // If any policy data has changed, delete/re-create
                        if ($policy->getImei() != $purchase->getImei() ||
                            $policy->getSerialNumber() != $purchase->getSerialNumber() ||
                            $policy->getPhone()->getId() != $purchase->getPhone()->getId()) {
                            $dm->remove($policy);
                            $dm->flush();
                            $policy = null;
                        }
                    }

                    if (!$policy) {
                        try {
                            $policyService = $this->get('app.policy');
                            $policy = $policyService->init(
                                $user,
                                $phone,
                                $purchase->getImei(),
                                $purchase->getSerialNumber(),
                                $this->getIdentityLogWeb($request)
                            );
                            $dm->persist($policy);
                        } catch (InvalidPremiumException $e) {
                            // Nothing the user can do, so rethow
                            throw $e;
                        } catch (InvalidUserDetailsException $e) {
                            $this->addFlash(
                                'error',
                                "Please check all your details.  It looks like we're missing something."
                            );
                            $allowPayment = false;
                        } catch (GeoRestrictedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you. It looks like you're outside the UK."
                            );
                            throw $this->createNotFoundException('Unable to see policy');
                        } catch (DuplicateImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (LostStolenImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (ImeiBlacklistedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        } catch (InvalidImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Looks like the IMEI you provided isn't quite right.  Please check the number again."
                            );
                            $allowPayment = false;
                        } catch (ImeiPhoneMismatchException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        } catch (RateLimitException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        }
                    }
                    $dm->flush();

                    return $this->redirectToRoute('purchase_step_review');
                }
            }
        }

        $data = array(
            'phone' => $phone,
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 3,
        );

        return $data;
    }

    /**
     * @Route("/step-review", name="purchase_step_review")
     * @Template
    */
    public function purchaseStepReviewAction(Request $request)
    {
        $user = $this->getUser();
        $policy = $user->getUnInitPolicy();
        if (!$policy) {
            return $this->redirectToRoute('purchase_step_phone');
            
        }

        $webpay = $this->get('app.judopay')->webpay(
            $policy,
            $policy->getPremium()->getMonthlyPremiumPrice(),
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        $data = [
            'phone' => $policy->getPhone(),
            'policy_key' => $this->getParameter('policy_key'),
            'webpay_action' => $webpay['post_url'],
            'webpay_reference' => $webpay['payment']->getReference(),
            'step' => 4,
            'is_postback' => 'POST' === $request->getMethod(),
        ];
        
        return $data;
    }

    /**
     * @Route("/cc/success", name="purchase_judopay_receive_success")
     * @Route("/cc/success/", name="purchase_judopay_receive_success_slash")
     * @Method({"POST"})
     */
    public function purchaseJudoPayReceiveSuccessAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['reference' => $request->get('Reference')]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        if ($payment->getUser()->getId() != $this->getUser()->getId()) {
            throw new AccessDeniedException('Unknown user');
        }

        $policy = $this->get('app.judopay')->add(
            $payment->getPolicy(),
            $request->get('ReceiptId'),
            null,
            $request->get('CardToken'),
            Payment::SOURCE_WEB
        );
        $this->addFlash(
            'success',
            'Welcome to so-sure!'
        );

        return $this->redirectToRoute('user_home');
    }
    /**
     * @Route("/cc/fail", name="purchase_judopay_receive_fail")
     * @Route("/cc/fail/", name="purchase_judopay_receive_fail_slash")
     */
    public function purchaseJudoPayFailAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Payment::class);
        $reference = $request->get('Reference');
        if (!$reference) {
            $initPolicies = $this->getUser()->getInitPolicies();
            if (count($initPolicies) > 0) {
                $this->addFlash('warning', 'You seem to have a policy that you started creating, but is unpaid.');
                return $this->redirectToRoute('purchase_step_review');
            }

            throw new \Exception('Unable to locate reference');
        }

        $payment = $repo->findOneBy(['reference' => $reference]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        if ($payment->getUser()->getId() != $this->getUser()->getId()) {
            throw new AccessDeniedException('Unknown user');
        }

        $this->addFlash('error', 'There was a problem processing your payment. You can try again.');

        return $this->redirectToRoute('purchase_step_review');
    }
}
