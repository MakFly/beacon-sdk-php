<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony;

/** Resolves the active Symfony Security user and pseudonymizes its identifier. */
final class HashedUserContextProvider implements UserContextProviderInterface
{
    public function __construct(
        private readonly ?object $tokenStorage,
        private readonly string $hashKey,
    ) {
    }

    public function userId(): ?string
    {
        if ($this->tokenStorage === null || $this->hashKey === '' || !method_exists($this->tokenStorage, 'getToken')) {
            return null;
        }

        try {
            $token = $this->tokenStorage->getToken();
            if (!\is_object($token) || !method_exists($token, 'getUser')) {
                return null;
            }
            $user = $token->getUser();
            if (!\is_object($user)) {
                return null;
            }
            $identifier = method_exists($user, 'getUserIdentifier')
                ? $user->getUserIdentifier()
                : (method_exists($user, 'getId') ? $user->getId() : null);
            if (!\is_scalar($identifier) || trim((string) $identifier) === '') {
                return null;
            }

            return 'usr_'.hash_hmac('sha256', (string) $identifier, $this->hashKey);
        } catch (\Throwable) {
            return null;
        }
    }
}
