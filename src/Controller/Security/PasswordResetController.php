<?php

namespace App\Controller\Security;

use App\Repository\UserRepository;
use App\Security\TurnstileAuthenticationSubscriber;
use App\Security\TurnstileVerifier;
use App\Service\DeferredMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles secure unauthenticated password resets under E2EE Pattern 2 (Institutional Escrow).
 */
#[Route('/user/password', name: 'user_password_', methods: ['GET', 'POST'])]
class PasswordResetController extends AbstractController
{
    /**
     * Floor, in microseconds, for the account-lookup portion of a forgot-password POST.
     * Must sit above the p99 of the slower (account exists) branch, otherwise the pad
     * becomes a no-op exactly when that branch is slow and the timing gap reopens.
     */
    private const int LOOKUP_TIME_FLOOR_US = 250_000;

    public function __construct(
        private EntityManagerInterface $em,
        private readonly TurnstileVerifier $turnstileVerifier,
        private readonly UserRepository $userRepository,
        private readonly DeferredMailer $deferredMailer,
    ) {}

    /**
     * Validates the Cloudflare Turnstile token attached to a submitted form.
     */
    private function isTurnstileValid(Request $request): bool
    {
        $token = (string) $request->request->get(TurnstileAuthenticationSubscriber::TOKEN_PARAMETER, '');

        return $this->turnstileVerifier->verify($token, $request->getClientIp());
    }

    /**
     * Blocks until LOOKUP_TIME_FLOOR_US has elapsed since $startedAt, so both branches
     * of the account lookup take the same observable time.
     *
     * A constant floor is used rather than a random delay on purpose: jitter only adds
     * noise, and an attacker averaging over enough samples still recovers the mean
     * difference. A fixed deadline leaks nothing.
     *
     * @param int $startedAt an hrtime(true) reading from before the lookup began
     */
    private function padToLookupTimeFloor(int $startedAt): void
    {
        $elapsedUs = intdiv(hrtime(true) - $startedAt, 1_000);

        if ($elapsedUs < self::LOOKUP_TIME_FLOOR_US) {
            usleep(self::LOOKUP_TIME_FLOOR_US - $elapsedUs);
        }
    }

    #[Route('/forgot/', name: 'forgot', methods: ['GET', 'POST'])]
    public function requestReset(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('forgot_password_request', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('user_password_forgot');
            }

            if (!$this->isTurnstileValid($request)) {
                $this->addFlash('danger', 'Bot verification failed. Please complete the challenge and try again.');
                return $this->redirectToRoute('user_password_forgot');
            }

            $emailInput = trim((string)$request->request->get('email'));
            if (empty($emailInput)) {
                $this->addFlash('danger', 'Please enter your email address.');
                return $this->redirectToRoute('user_password_forgot');
            }

            // Everything from here to padToLookupTimeFloor() must stay inside the timed
            // window — it is the only work that differs between a hit and a miss.
            $startedAt = hrtime(true);

            $user = $this->userRepository->findOneByEmail($emailInput);

            // Protect against user enumeration by displaying a success message regardless of email existence
            if ($user) {
                // Only the hash is stored, so a database read cannot be replayed as a
                // reset link. Issuing invalidates any token previously sent to this user.
                $resetToken = $user->issueResetToken();
                $this->em->flush();

                $resetUrl = $this->generateUrl('user_password_reset', ['token' => $resetToken],UrlGeneratorInterface::ABSOLUTE_URL);

                // Deferred to kernel.terminate rather than sent inline: the SMTP round-trip
                // would otherwise make this branch visibly slower than the not-found branch.
                $this->deferredMailer->defer(
                    new TemplatedEmail()
                        ->to($user->email)
                        ->subject('Reset Your Symfony Options Password')
                        ->htmlTemplate('emails/user_reset_password.html.twig')
                        ->context([
                            'resetUrl' => $resetUrl,
                        ])
                );
            }

            $this->padToLookupTimeFloor($startedAt);

            $this->addFlash('success', 'If a matching account exists, a secure password reset link has been dispatched to your inbox.');
            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/forgot_password_request.html.twig');
    }

    #[Route('/reset/{token}', name: 'reset', requirements: ['token' => '[0-9a-f]{64}'], methods: ['GET', 'POST'])]
    public function executeReset(
        string $token,
        Request $request,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('dashboard');
        }

        $user = $this->userRepository->findOneByResetToken($token);

        if (!$user || !$user->isResetTokenValid($token, new \DateTimeImmutable())) {
            $this->addFlash('danger', 'The password reset token is invalid, has expired, or has already been used.');
            return $this->redirectToRoute('user_password_forgot');
        }

        if ($request->isMethod('POST')) {
            $submittedToken = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('reset_forgotten_password_token', $submittedToken)) {
                $this->addFlash('danger', 'Invalid security token.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            if (!$this->isTurnstileValid($request)) {
                $this->addFlash('danger', 'Bot verification failed. Please complete the challenge and try again.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            $newPassword = $request->request->get('new_password');
            $confirmPassword = $request->request->get('confirm_password');

            if (empty($newPassword)) {
                $this->addFlash('danger', 'New Password missing.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('danger', 'Passwords do not match.');
                return $this->redirectToRoute('user_password_reset', ['token' => $token]);
            }

            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->password = $hashedPassword;

            // Burn the token so the link cannot be replayed.
            $user->clearResetToken();
            $this->em->flush();

            $this->addFlash('success', 'Your password has been successfully reset!');

            return $this->redirectToRoute('security_login');
        }

        return $this->render('security/reset_forgotten_password.html.twig', [
            'token' => $token,
            'userEmail' => $user->email,
        ]);
    }
}
