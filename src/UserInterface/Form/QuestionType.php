<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<QuestionData>
 */
final class QuestionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('question', TextareaType::class, [
            'label' => 'Your question',
            'attr' => ['maxlength' => 500, 'rows' => 2, 'placeholder' => 'When was the strange grey cat here?'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuestionData::class]);
    }
}
