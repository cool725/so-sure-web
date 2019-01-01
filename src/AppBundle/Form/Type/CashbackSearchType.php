<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Cashback;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class CashbackSearchType extends BaseType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    private $environment;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack, $environment)
    {
        $this->requestStack = $requestStack;
        $this->environment = $environment;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('status', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    Cashback::STATUS_PENDING_CLAIMABLE => Cashback::STATUS_PENDING_CLAIMABLE,
                    Cashback::STATUS_PENDING_PAYMENT => Cashback::STATUS_PENDING_PAYMENT,
                    Cashback::STATUS_PENDING_WAIT_CLAIM => Cashback::STATUS_PENDING_WAIT_CLAIM,
                    Cashback::STATUS_PAID => Cashback::STATUS_PAID,
                    Cashback::STATUS_CLAIMED => Cashback::STATUS_CLAIMED,
                    Cashback::STATUS_FAILED => Cashback::STATUS_FAILED,
                    Cashback::STATUS_MISSING => Cashback::STATUS_MISSING,
                ]
            ])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            if ($currentRequest && $currentRequest->query->get('status')) {
                $this->formQuerystring($form, $currentRequest, 'status');
            } else {
                $form->get('status')->setData([Cashback::STATUS_PENDING_PAYMENT]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }
}
