<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\Chargebacks;
use AppBundle\Repository\ChargebackPaymentRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use AppBundle\Document\CurrencyTrait;

class ChargebacksType extends AbstractType
{
    use CurrencyTrait;

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
        $builder->add('add', SubmitType::class);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var Chargebacks $chargeback */
            $chargeback = $event->getData();
            $premium = $chargeback->getPolicy()->getPremium();
            $form = $event->getForm();
            $amount = $this->toTwoDp(
                $premium ?  0 - $chargeback->getPolicy()->getPremium()->getAdjustedStandardMonthlyPremiumPrice() : 0
            );
            $form->add('chargeback', DocumentType::class, [
                    'placeholder' => 'Select a chargeback',
                    'class' => 'AppBundle:Payment\ChargebackPayment',
                    'query_builder' => function (ChargebackPaymentRepository $dr) use ($amount) {
                        return $dr->findUnassigned($amount);
                    },
                    'choice_value' => 'id',
                    'choice_label' => 'reference',
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Chargebacks',
        ));
    }
}
