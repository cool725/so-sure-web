<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\HttpFoundation\RequestStack;

class ClaimSearchType extends BaseType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('id', TextType::class, ['required' => false])
            ->add('number', TextType::class, ['required' => false])
            ->add('status', ChoiceType::class, [
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    Claim::STATUS_APPROVED => Claim::STATUS_APPROVED,
                    Claim::STATUS_INREVIEW => Claim::STATUS_INREVIEW,
                    Claim::STATUS_WITHDRAWN => Claim::STATUS_WITHDRAWN,
                    Claim::STATUS_DECLINED => Claim::STATUS_DECLINED,
                    Claim::STATUS_SETTLED => Claim::STATUS_SETTLED,
                    Claim::STATUS_PENDING_CLOSED => Claim::STATUS_PENDING_CLOSED,
                ],
                'data' => [Claim::STATUS_INREVIEW, Claim::STATUS_APPROVED],
            ])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            $this->formQuerystring($form, $currentRequest, 'status');
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
