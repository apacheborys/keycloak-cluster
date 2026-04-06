# Use Case 2: Delegating Authentication and Authorization to Keycloak

## When this is useful

Use this pattern when your frontend authenticates users directly against Keycloak (OIDC authorization code flow or other compatible OAuth2/OIDC flow), then sends JWT access tokens to your Symfony API.

Your Symfony application becomes a resource server:

- no local password checks
- JWT validation is delegated to Keycloak signing keys
- API access decisions are driven by token claims and roles

## Sequence diagram

```mermaid
sequenceDiagram
    participant U as User Browser / SPA
    participant FE as Frontend
    participant KC as Keycloak
    participant API as Symfony API
    participant Auth as KeycloakJwtAuthenticator

    U->>FE: Open app
    FE->>KC: Redirect to OIDC login
    KC-->>FE: Authorization code / tokens
    FE->>API: Request with Bearer access token
    API->>Auth: supports() + authenticate()
    Auth->>KC: Verify signature/issuer/claims
    KC-->>Auth: Validation metadata/JWKS
    Auth-->>API: Authenticated token user
    API-->>FE: Protected resource response
```

## Minimal configuration model

1. Configure bundle connection in `config/packages/keycloak_bridge.yaml`.
2. Register security firewall authenticator (`KeycloakJwtAuthenticator`).
3. Protect API routes with `access_control`.
4. Use token roles in voters/attributes/`IsGranted` checks.

## Example: security configuration sketch

```yaml
# config/packages/security.yaml
security:
  enable_authenticator_manager: true

  providers:
    keycloak_jwt_provider:
      id: Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtUserProvider

  firewalls:
    api:
      pattern: ^/api
      stateless: true
      provider: keycloak_jwt_provider
      custom_authenticators:
        - Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator

  access_control:
    - { path: ^/api/admin, roles: ROLE_ADMIN }
    - { path: ^/api, roles: ROLE_USER }
```

## Example: controller that reads authenticated principal

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    #[Route('/api/profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function profile(): JsonResponse
    {
        /** @var KeycloakJwtUser $user */
        $user = $this->getUser();

        return new JsonResponse([
            'identifier' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
```

## Operational guidance

- Keep API firewall stateless.
- Verify that issuer in token matches your configured Keycloak base URL.
- Keep scopes and role mapping conventions explicit across frontend and backend.
- In this demo stack, JWT debug endpoints are available:
  - `POST /api/keycloak/verify`
  - `GET /api/keycloak/me`
