<?php

namespace AppBundle\Service;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Exception\MissingDependencyException;
use AppBundle\Helpers\MobileNumberHelper;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Symfony\Component\Form\FormInterface;

/**
 * Class SearchService
 * @package AppBundle\Service
 */
class SearchService
{
    /**
     * @var DocumentManager
     */
    private $dm;

    private $form;

    /**
     * @var UserRepository
     */
    private $userRepo;

    /**
     * @var Builder
     */
    private $userQb;

    /**
     * @var  PolicyRepository
     */
    private $policyRepo;

    /**
     * @var Builder
     */
    private $policyQb;

    /**
     * @var bool
     */
    private $searchWithUsers = false;

    /**
     * The environment the service is operating in.
     * @var string
     */
    private $env;

    public function __construct(DocumentManager $dm, $env, FormInterface $form = null)
    {
        $this->dm = $dm;
        $this->env = $env;
        $this->form = $form;
        $this->initQueryBuilders();
    }

    public function getForm(): FormInterface
    {
        if ($this->form == null) {
            throw new MissingDependencyException("The form has not been set, please set the form before continuing");
        }
        return $this->form;
    }

    public function setForm(FormInterface $form): SearchService
    {
        $this->form = $form;
        return $this;
    }

    public function searchPolicies()
    {
        if (empty($this->form->getNormData())) {
            return $this->policyQb->sort('created', -1)
                ->limit(50)
                ->getQuery()
                ->execute()
                ->toArray();
        }
        $data = $this->form->getNormData();
        if (array_key_exists('policy', $data)) {
            if ($this->env == 'prod') {
                $data['policy'] = mb_convert_case($data['policy'], MB_CASE_TITLE);
            } else {
                $data['policy'] = mb_convert_case($data['policy'], MB_CASE_UPPER);
            }
        }
        if ($data['mobile']) {
            $formatter = new MobileNumberHelper($data['mobile']);
            /**
             * The db stores the mobile number in mobile format i.e +44...
             * So that is the method we will use for this query.
             */
            $data['mobile'] = $formatter->getMongoRegexFormat();
        }
        if (!array_key_exists('invalid', $data)) {
            $data['invalid'] = 0;
        }
        unset($data['invalid']);
        $this->policyQb->eagerCursor(true);
        $userMap = [
            'email' => 'emailCanonical',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'mobile' => 'mobileNumber',
            'facebookId' => 'facebookId'
        ];

        $map = [
            'bacsReference' => 'paymentMethod.bankAccount.reference',
            'paymentMethod' => 'paymentMethod.type',
            'policy' => 'policyNumber',
            'postcode' => 'billingAddress.postcode',
            'serial' => 'serialNumber'
        ];
        $fields = array_keys($data);
        $this->addStatusQuery($data['status']);
        foreach ($fields as $field) {
            if ($field === "status") {
                continue;
            } elseif (!empty($data[$field])) {
                if (array_key_exists($field, $map)) {
                    $this->policyQb->field($map[$field])->equals($data[$field]);
                } elseif (array_key_exists($field, $userMap)) {
                    $this->searchWithUsers = true;
                    $this->userQb->addAnd([$userMap[$field] => new \MongoRegex('/' . $data[$field] . '/i')]);
                } else {
                    $this->policyQb->field($field)->equals($data[$field]);
                }
            }
        }
        if ($this->searchWithUsers) {
            $users = $this->userQb->getQuery()->execute()->toArray();
            $searchUsers = [];
            foreach ($users as $user) {
                $searchUsers[] = new \MongoId($user->getId());
            }

            if (!empty($searchUsers)) {
                $this->policyQb->addAnd(
                    $this->policyQb->expr()->field('user.$id')->in($searchUsers)
                );
            } else {
                return $searchUsers;
            }
        }
        return $this->sortResults($data['status']);
    }

    public function initQueryBuilders()
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $userQb = $userRepo->createQueryBuilder();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policyQb = $policyRepo->createQueryBuilder();

        $this->userRepo = $userRepo;
        $this->userQb = $userQb;
        $this->policyRepo = $policyRepo;
        $this->policyQb = $policyQb;
    }

    private function addStatusQuery($status)
    {
        if ($status == 'current') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'current-discounted') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID])
            );
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('policyDiscountPresent')->equals(true)
            );
        } elseif ($status == 'past-due') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_CANCELLED])
            );
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('cancelledReason')->notIn([Policy::CANCELLED_UPGRADE])
            );
        } elseif ($status == 'call') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_UNPAID])
            );
        } elseif ($status == 'called') {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('notesList.type')->equals('call')
            );
            $oneWeekAgo = \DateTime::createFromFormat('U', time());
            $oneWeekAgo = $oneWeekAgo->sub(new \DateInterval('P7D'));
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('notesList.date')->gte($oneWeekAgo)
            );
        } elseif ($status == Policy::STATUS_EXPIRED_CLAIMABLE) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_CLAIMABLE])
            );
        } elseif ($status == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_EXPIRED_WAIT_CLAIM])
            );
        } elseif ($status == Policy::STATUS_PENDING_RENEWAL) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->in([Policy::STATUS_PENDING_RENEWAL])
            );
        } elseif ($status !== null) {
            $this->policyQb->addAnd(
                $this->policyQb->expr()->field('status')->equals($status)
            );
        }
    }

    private function sortResults($status)
    {
        if ($status == Policy::STATUS_UNPAID) {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } elseif ($status == 'past-due') {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                return $policy->isCancelledAndPaymentOwed();
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } elseif ($status == 'call') {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            $policies = array_filter($policies, function ($policy) {
                /** @var Policy $policy */
                $fourteenDays = \DateTime::createFromFormat('U', time());
                $fourteenDays = $fourteenDays->sub(new \DateInterval('P14D'));
                $sevenDays = \DateTime::createFromFormat('U', time());
                $sevenDays = $fourteenDays->sub(new \DateInterval('P7D'));

                // 14 days & no calls or 7 days & at most 1 call
                if ($policy->getPolicyExpirationDateDays() <= 14 && $policy->getNoteCalledCount($fourteenDays) == 0) {
                    return true;
                } elseif ($policy->getPolicyExpirationDateDays() <= 7 &&
                    $policy->getNoteCalledCount($fourteenDays) <= 1) {
                    return true;
                } else {
                    return false;
                }
            });
            // sort older to more recent
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() > $b->getPolicyExpirationDate();
            });
        } else {
            $policies = $this->policyQb->getQuery()->execute()->toArray();
            //Sort newer to older
            usort($policies, function ($a, $b) {
                return $a->getPolicyExpirationDate() < $b->getPolicyExpirationDate();
            });
        }
        return $policies;
    }
}
