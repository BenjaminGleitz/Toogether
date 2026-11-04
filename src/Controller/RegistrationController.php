<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private readonly EmailVerifier $emailVerifier)
    {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager): Response
    {
        if ($security->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            $entityManager->persist($user);
            $entityManager->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation($user);

            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator, UserRepository $userRepository): Response
    {
        $id = $request->query->get('id');

        if (null === $id) {
            return $this->redirectToRoute('app_register');
        }

        $user = $userRepository->find($id);

        if (null === $user) {
            return $this->redirectToRoute('app_register');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_home');
        }

        $this->addFlash('success', $translator->trans('user.registration.flash.email_verified'));

        return $this->redirectToRoute('app_home');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED')]
    #[IsCsrfTokenValid('verify_resend')]
    public function resendVerification(#[CurrentUser] User $user, RateLimiterFactoryInterface $resendVerificationLimiter, TranslatorInterface $translator): Response
    {
        if ($user->isVerified()) {
            $this->addFlash('info', $translator->trans('user.registration.flash.email_already_verified'));

            return $this->redirectToRoute('app_home');
        }

        $limiter = $resendVerificationLimiter->create((string) $user->getId());
        if (!$limiter->consume()->isAccepted()) {
            $this->addFlash('warning', $translator->trans('user.registration.flash.email_resend_too_many'));

            return $this->redirectToRoute('app_home');
        }

        $this->emailVerifier->sendEmailConfirmation($user);

        $this->addFlash('success', $translator->trans('user.registration.flash.email_resend_success'));

        return $this->redirectToRoute('app_home');
    }
}
