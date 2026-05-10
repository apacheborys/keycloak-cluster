<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[Route('/api/protected', name: 'api_protected_')]
final readonly class ProtectedDemoController
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof UserInterface) {
            return new JsonResponse([
                'message' => 'Authenticated user is not available.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'user_identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'user_class' => $user::class,
            'token_class' => $token !== null ? $token::class : null,
        ]);
    }
}
