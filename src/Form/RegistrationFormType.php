<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir un nom d\'utilisateur.'),
                    new Length(
                        min: 3,
                        minMessage: 'Le nom d\'utilisateur doit faire au moins {{ limit }} caractères.',
                        max: 180,
                    ),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                    new Length(
                        min: 6,
                        minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
                        max: 4096,
                    ),
                    new NotCompromisedPassword(
                        message: 'Ce mot de passe a été divulgué dans des fuites de données. Veuillez en choisir un autre.',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'constraints' => [
                new UniqueEntity(
                    fields: 'username',
                    message: 'Ce nom d\'utilisateur est déjà pris.',
                ),
            ],
        ]);
    }
}
