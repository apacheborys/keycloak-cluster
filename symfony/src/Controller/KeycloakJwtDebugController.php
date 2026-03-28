<?php

declare(strict_types=1);

namespace App\Controller;

use Apacheborys\KeycloakPhpClient\Entity\JsonWebToken;
use Apacheborys\KeycloakPhpClient\Service\KeycloakJwtVerificationServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/keycloak', name: 'api_keycloak_')]
final readonly class KeycloakJwtDebugController
{
    public function __construct(
        private KeycloakJwtVerificationServiceInterface $jwtVerificationService,
    ) {
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $token = $this->extractBearerToken($request) ?? $this->extractTokenFromPayload($request);
        if ($token === null) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'JWT token is required. Pass Bearer token or JSON body {"token":"..."}.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $isValid = $this->jwtVerificationService->verifyJwt($token);
        if (!$isValid) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'JWT signature or claims validation failed.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $jwt = JsonWebToken::fromRawToken($token);
        } catch (Throwable) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'JWT could not be parsed.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = $jwt->getPayload();

        return new JsonResponse([
            'valid' => true,
            'issuer' => $payload->getIss(),
            'subject' => $payload->getSub()->toString(),
            'preferred_username' => $payload->getPreferredUsername(),
            'client_id' => $payload->getClientId(),
            'expires_at' => $payload->getExp()->format(DATE_ATOM),
        ]);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return new JsonResponse([
                'message' => 'Authorization Bearer token is required.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        if (!$this->jwtVerificationService->verifyJwt($token)) {
            return new JsonResponse([
                'message' => 'JWT validation failed.',
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $jwt = JsonWebToken::fromRawToken($token);
        } catch (Throwable) {
            return new JsonResponse([
                'message' => 'JWT is malformed.',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = $jwt->getPayload();

        return new JsonResponse([
            'user_identifier' => $payload->getPreferredUsername() !== ''
                ? $payload->getPreferredUsername()
                : $payload->getSub()->toString(),
            'issuer' => $payload->getIss(),
            'subject' => $payload->getSub()->toString(),
            'scope' => $payload->getScope(),
            'roles' => $this->extractRoles($jwt),
            'expires_at' => $payload->getExp()->format(DATE_ATOM),
        ]);
    }

    /**
     * @return list<string>
     */
    private function extractRoles(JsonWebToken $jwt): array
    {
        $roles = [];

        foreach ($jwt->getPayload()->getRealmAccess()['roles'] as $realmRole) {
            if (is_string($realmRole) && trim($realmRole) !== '') {
                $roles[trim($realmRole)] = true;
            }
        }

        foreach ($jwt->getPayload()->getResourceAccess() as $resourceAccess) {
            foreach ($resourceAccess['roles'] as $resourceRole) {
                if (is_string($resourceRole) && trim($resourceRole) !== '') {
                    $roles[trim($resourceRole)] = true;
                }
            }
        }

        return array_keys($roles);
    }

    private function extractTokenFromPayload(Request $request): ?string
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return null;
        }

        $token = $payload['token'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            return null;
        }

        return trim($token);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header) || $header === '') {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        if ($token === '') {
            return null;
        }

        return $token;
    }
}
