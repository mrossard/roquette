<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('username', TextType::class, [
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir un nom d\'utilisateur.'),
                new Length(
                    min: 3,
                    max: 180,
                    minMessage: 'Le nom d\'utilisateur doit faire au moins {{ limit }} caractères.',
                ),
                new Callback(static function (mixed $value, ExecutionContextInterface $context) {
                    if (is_string($value) && strcasecmp($value, User::ROBOT_USERNAME) === 0) {
                        $context
                            ->buildViolation('Ce nom d\'utilisateur est réservé par le système.')
                            ->setTranslationDomain('messages')
                            ->addViolation();
                    }
                }),
            ],
        ])->add('email', EmailType::class, [
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir une adresse email.'),
                new Email(message: 'Veuillez saisir une adresse email valide.'),
                new Length(max: 180, maxMessage: 'L\'adresse email ne doit pas dépasser {{ limit }} caractères.'),
            ],
        ])->add('plainPassword', PasswordType::class, [
            'mapped' => false,
            'attr' => ['autocomplete' => 'new-password'],
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                new Length(
                    min: 6,
                    max: 4096,
                    minMessage: 'Le mot de passe doit faire au moins {{ limit }} caractères.',
                ),
                new NotCompromisedPassword(
                    message: 'Ce mot de passe a été divulgué dans des fuites de données. Veuillez en choisir un autre.',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'constraints' => [
                new UniqueEntity(fields: 'username', message: 'Ce nom d\'utilisateur est déjà pris.'),
                new UniqueEntity(fields: 'email', message: 'Cette adresse email est déjà utilisée.'),
                new UniqueEntity(
                    fields: 'slug',
                    errorPath: 'username',
                    message: 'Ce nom d\'utilisateur est déjà pris.',
                ),
            ],
        ]);
    }
}
