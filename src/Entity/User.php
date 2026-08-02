<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erkens\Security\TwoFactorTextBundle\Model\TwoFactorTextInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface as EmailTwoFactorInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Stringable;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Index(name: 'idx_user_reset_token_hash', columns: ['reset_token_hash'])]
#[UniqueEntity(fields: ['email'], message: 'This email address is already in use.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, EmailTwoFactorInterface, TwoFactorTextInterface, Stringable
{
    /**
     * How long a password reset link stays usable. Mirrored in the copy of
     * emails/user_reset_password.html.twig.
     */
    public const string RESET_TOKEN_LIFETIME = '+1 hour';

    // can impersonate another user in url by adding ?_switch_user=jsmith to impersonate jsmith
    public function __construct()
    {
        $this->logins = new ArrayCollection();
    }

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private(set) ?int $id = null;

    #[ORM\Column]
    #[Assert\NotBlank, Assert\Email]
    public ?string $email = null;

    #[ORM\Column(nullable: true)]
    public ?string $password = null;

    #[Assert\NotBlank(groups: ['Registration'])]
    public ?string $plainPassword = null;

    #[ORM\Column(type: Types::JSON)]
    public array $roles = [];

    #[ORM\Column]
    #[Assert\NotBlank]
    public ?string $firstName = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    public ?string $lastName = null;

    #[ORM\Column(nullable: true)]
    public ?string $credentials = null;

    #[ORM\OneToMany(targetEntity: Login::class, mappedBy: 'user')]
    #[ORM\OrderBy(['loginTime' => 'DESC'])]
    public Collection $logins;

    #[ORM\Column(nullable: true)]
    public ?string $confirmationHash = null;

    /**
     * SHA-256 of the raw password reset token. The raw token is only ever sent
     * in the reset email and is never persisted.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $resetTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column]
    public bool $isActivated = false;

    #[ORM\Column(nullable: true)]
    public ?string $cellNumber = null;

    #[ORM\Column(nullable: true)]
    public ?string $totpSecret = null;

    #[ORM\Column]
    public ?bool $isTotpConfirmed = false;

    #[ORM\Column(nullable: true)]
    public ?string $textAuthCode = null;

    #[ORM\Column(nullable: true)]
    public ?string $emailAuthCode = null;

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        if (!in_array('ROLE_USER', $roles)) {
            $roles[] = 'ROLE_USER';
        }

        return $roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    /**
     * The raw token carries 256 bits of entropy, so a plain SHA-256 is enough here —
     * it is not guessable and does not need a slow password hash.
     */
    public static function hashResetToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Issues a fresh reset token, invalidating any token previously issued to this
     * user, and returns the raw value. Only the hash is retained, so this is the one
     * and only chance to read the raw token.
     */
    public function issueResetToken(): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $this->setResetToken($rawToken, new \DateTimeImmutable(self::RESET_TOKEN_LIFETIME));

        return $rawToken;
    }

    public function setResetToken(string $rawToken, \DateTimeImmutable $expiresAt): void
    {
        $this->resetTokenHash = self::hashResetToken($rawToken);
        $this->resetTokenExpiresAt = $expiresAt;
    }

    public function clearResetToken(): void
    {
        $this->resetTokenHash = null;
        $this->resetTokenExpiresAt = null;
    }

    public function isResetTokenValid(string $rawToken, \DateTimeImmutable $now): bool
    {
        if (null === $this->resetTokenHash || null === $this->resetTokenExpiresAt) {
            return false;
        }

        if ($this->resetTokenExpiresAt <= $now) {
            return false;
        }

        return hash_equals($this->resetTokenHash, self::hashResetToken($rawToken));
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);

        return $data;
    }

    public ?Login $lastLogin {
        get {
            // these are ordered by login_date DESC,
            // so most recent login is first in the list
            return $this->logins->first() ?: null;
        }
    }

    public string $name {
        get {
            $name = $this->firstName.' '.$this->lastName;
            $name .= (strlen((string) $this->credentials) > 0) ? ', '.$this->credentials : '';

            return $name;
        }
    }

    public string $lfname {
        get => $this->lastName.', '.$this->firstName;
    }

    public function __toString(): string
    {
        return $this->lfname;
    }

    public function isTotpSecretSet(): string
    {
        return (bool) $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): self
    {
        $this->totpSecret = $totpSecret;

        return $this;
    }

    public function isTotpConfirmed(): bool
    {
        return $this->isTotpConfirmed;
    }

    public function setIsTotpConfirmed(bool $isTotpConfirmed): self
    {
        $this->isTotpConfirmed = $isTotpConfirmed;

        return $this;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret && $this->isTotpConfirmed;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isTextAuthEnabled(): bool
    {
        return (bool) $this->cellNumber;
    }

    public function getTextAuthRecipient(): string
    {
        return $this->cellNumber;
    }

    public function getTextAuthCode(): string
    {
        if (null === $this->textAuthCode) {
            throw new \LogicException('The text authentication code was not set');
        }

        return $this->textAuthCode;
    }

    public function setTextAuthCode(?string $authCode): void
    {
        $this->textAuthCode = $authCode;
    }

    public function isEmailAuthEnabled(): bool
    {
        return (bool) $this->email;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    public function getEmailAuthCode(): ?string
    {
        if (null === $this->emailAuthCode) {
            throw new \LogicException('The email authentication code was not set');
        }

        return $this->emailAuthCode;
    }

    public function setEmailAuthCode(?string $authCode): void
    {
        $this->emailAuthCode = $authCode;
    }
}
