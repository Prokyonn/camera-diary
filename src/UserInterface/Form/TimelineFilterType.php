<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TimelineFilter>
 */
final class TimelineFilterType extends AbstractType
{
    public function __construct(
        private readonly CameraRepositoryInterface $cameras,
        private readonly SettingsProviderInterface $settings,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('day', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false])
            ->add('camera', ChoiceType::class, [
                'choices' => $this->cameraChoices(),
                'required' => false,
                'placeholder' => 'All cameras',
            ])
            ->add('type', ChoiceType::class, [
                'choices' => $this->detectionTypeChoices(),
                'required' => false,
                'placeholder' => 'Everything',
            ])
            ->add('falseAlarms', CheckboxType::class, ['required' => false, 'label' => 'Show false alarms']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TimelineFilter::class,
            'method' => 'GET',
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }

    /**
     * @return array<string, string> label => name
     */
    private function detectionTypeChoices(): array
    {
        $choices = [];
        foreach ($this->settings->settings()->detectionTypes->terms as $type) {
            $choices[$type->label] = $type->name;
        }

        return $choices;
    }

    /**
     * @return array<string, string> name => slug
     */
    private function cameraChoices(): array
    {
        $choices = [];
        foreach ($this->cameras->findBy([], ['name' => 'asc']) as $camera) {
            $choices[$camera->getName()] = $camera->getSlug();
        }

        return $choices;
    }
}
