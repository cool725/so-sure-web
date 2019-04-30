<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Bacs;
use AppBundle\Service\PCAService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use AppBundle\Exception\DirectDebitBankException;
use Symfony\Component\Form\FormError;

class BacsConfirmType extends AbstractType
{
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
        $builder
            ->add('accountName', HiddenType::class, ['required' => true])
            ->add('sortCode', HiddenType::class, ['required' => true])
            ->add('accountNumber', HiddenType::class, ['required' => true])
            ->add('reference', HiddenType::class, ['required' => true])
            ->add('billingDate', HiddenType::class, ['required' => true])
            ->add('save', SubmitType::class)
            ->add('back', SubmitType::class)
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
}
