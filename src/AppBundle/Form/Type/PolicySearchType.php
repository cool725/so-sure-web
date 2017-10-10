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
use AppBundle\Document\Policy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PolicySearchType extends AbstractType
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
        // TODO: Determine if claims or admin search and hide a few statuses
        $statuses = [
            'Any' => null,
            'Current' => 'current',
            'Current w/Discount' => 'current-discounted',
            Policy::STATUS_PENDING => Policy::STATUS_PENDING,
            Policy::STATUS_ACTIVE => Policy::STATUS_ACTIVE,
            Policy::STATUS_CANCELLED => Policy::STATUS_CANCELLED,
            Policy::STATUS_EXPIRED => Policy::STATUS_EXPIRED,
            Policy::STATUS_EXPIRED_CLAIMABLE => Policy::STATUS_EXPIRED_CLAIMABLE,
            Policy::STATUS_EXPIRED_WAIT_CLAIM => Policy::STATUS_EXPIRED_WAIT_CLAIM,
            Policy::STATUS_UNPAID => Policy::STATUS_UNPAID,
            Policy::STATUS_PENDING_RENEWAL => Policy::STATUS_PENDING_RENEWAL,
            Policy::STATUS_RENEWAL => Policy::STATUS_RENEWAL,
            'Past Due (cancelled polices w/claim)' => 'past-due',
        ];
        $builder
            ->add('email', TextType::class, ['required' => false])
            ->add('mobile', TextType::class, ['required' => false])
            ->add('postcode', TextType::class, ['required' => false])
            ->add('lastname', TextType::class, ['required' => false])
            ->add('policy', TextType::class, ['required' => false])
            ->add('imei', TextType::class, ['required' => false])
            ->add('facebookId', TextType::class, ['required' => false])
            ->add('status', ChoiceType::class, [
                'required' => false,
                'data' => 'current',
                'choices' => $statuses,
            ])
            ->add('sosure', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'IMEI' => [
                        'Patrick iPhone' => '355424073417084',
                        'iPhone 6S Plus' => '353287074257748',
                        'Julien 5c' => '013834002513072',
                        'Jamie Nexus 5X' => '353627075075872',
                        'Jamie iPhone' => '359285060633868',
                    ],
                    'Facebook' => [
                        'Patrick (Global?)' => '10153878106240169',
                        'Patrick (App Scoped?)' => '10153899912245169',
                    ]
                ]
            ])
            ->add('invalid', CheckboxType::class, ['required' => false])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            $form->get('email')->setData($currentRequest->query->get('email'));
            $form->get('mobile')->setData($currentRequest->query->get('mobile'));
            $form->get('postcode')->setData($currentRequest->query->get('postcode'));
            $form->get('lastname')->setData($currentRequest->query->get('lastname'));
            $form->get('policy')->setData($currentRequest->query->get('policy'));
            $form->get('status')->setData($currentRequest->query->get('status'));
            $form->get('imei')->setData($currentRequest->query->get('imei'));
            $form->get('facebookId')->setData($currentRequest->query->get('facebookId'));
            if ($currentRequest->query->get('invalid') !== null) {
                $form->get('invalid')->setData((bool) $currentRequest->query->get('invalid'));
            } else {
                $form->get('invalid')->setData($this->environment != 'prod');
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
