<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\Gender;
use App\Validator\PasswordRequirements;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use function Symfony\Component\Translation\t;

/**
 * @extends AbstractType<User>
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => t('user.field.email'),
                'attr' => [
                    'autocomplete' => 'email',
                ],
            ])
            ->add('firstname', null, [
                'label' => t('user.field.firstname'),
                'attr' => [
                    'autocomplete' => 'given-name',
                ],
            ])
            ->add('lastname', null, [
                'label' => t('user.field.lastname'),
                'attr' => [
                    'autocomplete' => 'family-name',
                ],
            ])
            ->add('gender', EnumType::class, [
                'class' => Gender::class,
                'label' => t('user.field.gender'),
                'expanded' => true,
            ])
            ->add('birthdate', BirthdayType::class, [
                'label' => t('user.field.birthdate'),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [new PasswordRequirements()],
                'label' => t('user.field.password'),
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue(
                        message: 'user.registration.agree_terms.not_true',
                    ),
                ],
                'label' => t('user.registration.agree_terms'),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
