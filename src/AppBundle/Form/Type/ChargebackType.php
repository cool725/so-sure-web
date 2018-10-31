<?php

namespace AppBundle\Form\Type;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;

class ChargebackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $now = \DateTime::createFromFormat('U', time());
        $year = $now->format('Y');
        $years = [$year, $year - 1];

        $builder
            ->add('reference', TextType::class, ['required' => true])
            ->add('amount', TextType::class, ['required' => true])
            ->add('date', DateType::class, ['required' => true, 'years' => $years])
            ->add('refundTotalCommission', TextType::class, ['required' => true])
            ->add('add', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Payment\ChargebackPayment',
        ));
    }
}
