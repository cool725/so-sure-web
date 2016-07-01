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

class YearMonthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $now = new \DateTime();
        for ($i = 2016; $i <= $now->format('Y'); $i++) {
            $years[$i] = $i;
        }
        $builder
            ->add('year', ChoiceType::class, ['choices' => $years])
            ->add('month', ChoiceType::class, [
                'choices' => [
                    '01' => 'Jan',
                    '02' => 'Feb',
                    '03' => 'Mar',
                    '04' => 'Apr',
                    '05' => 'May',
                    '06' => 'Jun',
                    '07' => 'July',
                    '08' => 'Aug',
                    '09' => 'Sept',
                    '10' => 'Oct',
                    '11' => 'Nov',
                    '12' => 'Dec',
                ]
            ])
            ->add('submit', SubmitType::class, array('label' => 'Show Accounts'))
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
