<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Bacs;
use AppBundle\Service\PCAService;
use AppBundle\Exception\DirectDebitBankException;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Policy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\HttpFoundation\RequestStack;

class BacsType extends AbstractType
{
    use DateTrait;

    /**
     * @var boolean
     */
    private $required;

    /**
     * @var PCAService
     */
    private $pcaService;

    /**
     * @param PCAService $pcaService
     * @param boolean    $required
     */
    public function __construct(PCAService $pcaService, $required)
    {
        $this->pcaService = $pcaService;
        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {

        $days = $this->getEligibleBillingDays();
        $indexedDays = [];
        foreach ($days as $day) {
            $indexedDays[$day + 1] = $day;
        }
        $today = $this->adjustDayForBilling(new \DateTime())->format("d") - 1;

        $builder
            ->add('accountName', TextType::class, ['required' => $this->required])
            ->add('validateName', HiddenType::class)
            ->add('sortCode', TextType::class, ['required' => $this->required, 'attr' => ['maxlength' => 8]])
            ->add('validateSortCode', TextType::class, ['required' => $this->required])
            ->add('accountNumber', TextType::class, ['required' => $this->required])
            ->add('billingDate', ChoiceType::class, [
                'placeholder' => 'Select date...',
                'required' => $this->required,
                'choices' => $indexedDays,
                'data' => $today,
            ])
            ->add('validateAccountNumber', TextType::class, ['required' => $this->required])
            ->add('soleSignature', CheckboxType::class, [
                'label' => 'I am the sole signature on the account',
                'required' => $this->required
            ])
            ->add('save', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            /** @var Bacs $bacs */
            $bacs = $event->getData();
            $form = $event->getForm();
            try {
                // TODO: Add user
                $bankAccount = $this->pcaService->getBankAccount($bacs->getSortCode(), $bacs->getAccountNumber());
                $bacs->setBankAccount($bankAccount);
            } catch (DirectDebitBankException $e) {
                if ($e->getCode() == DirectDebitBankException::ERROR_SORT_CODE) {
                    $form->get('sortCode')->addError(new FormError('Sort code does not exist'));
                } elseif ($e->getCode() == DirectDebitBankException::ERROR_ACCOUNT_NUMBER) {
                    $form->get('accountNumber')->addError(
                        new FormError('Account number is invalid for this sort code')
                    );
                } elseif ($e->getCode() == DirectDebitBankException::ERROR_NON_DIRECT_DEBIT) {
                    $form->get('accountNumber')->addError(new FormError('Account does not support direct debit'));
                }
            }

        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Bacs',
        ));
    }

    /**
     * Gets a list of dates of the month upon which a user could validly set their billing to occur.
     * @param \DateTime $date is the date that we are checking this on, with null defaulting to now.
     * @return array containing dates of this month on which recurring payments could come in the next months on the
     *               same day of the month.
     */
    private function getEligibleBillingDays($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        // wait 4 business days. first payment is scheduled 2 days later, and 2 days for payment to be run.
        $initialDone = $this->addDays($this->addBusinessDays($date, 2), 2);
        $startOfMonth = $this->startOfMonth($date);
        $endOfMonth = $this->endOfMonth($date);
        if ($initialDone > $endOfMonth) {
            $i = (int)$initialDone->format("d");
        } else {
            $i = 0;
        }
        $days = [];
        for ($i; $i < 28; $i++) {
            $days[] = $i;
        }
        return $days;
    }
}
