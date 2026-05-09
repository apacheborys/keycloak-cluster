<?php

declare(strict_types=1);

namespace App\Keycloak;

use Apacheborys\KeycloakPhpClient\Service\KeycloakJwtVerificationServiceInterface;
use Apacheborys\KeycloakPhpClient\ValueObject\KeycloakClientConfig;
use Apacheborys\SymfonyKeycloakBridgeBundle\Security\KeycloakJwtAuthenticator;
use Apacheborys\SymfonyKeycloakBridgeBundle\Service\Internal\CallsignValuePrefixer;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class KeycloakJwtAuthenticatorFactory
{
    public function __construct(
        private KeycloakJwtVerificationServiceInterface $jwtVerificationService,
        private KeycloakClientConfig $keycloakClientConfig,
        #[AutowireIterator('keycloak.user_entity_config')]
        private iterable $userEntityConfigs,
        private CallsignValuePrefixer $callsignValuePrefixer,
        private KeycloakIssuerBaseUrlResolver $issuerBaseUrlResolver,
    ) {
    }

    public function createForIssuer(string $issuer): KeycloakJwtAuthenticator
    {
        $derivedConfig = new KeycloakClientConfig(
            baseUrl: $this->issuerBaseUrlResolver->resolve($issuer),
            clientRealm: $this->keycloakClientConfig->getClientRealm(),
            clientId: $this->keycloakClientConfig->getClientId(),
            clientSecret: $this->keycloakClientConfig->getClientSecret(),
            realmListTtl: $this->keycloakClientConfig->getRealmListTtl(),
        );

        return new KeycloakJwtAuthenticator(
            jwtVerificationService: $this->jwtVerificationService,
            keycloakClientConfig: $derivedConfig,
            userEntityConfigs: $this->userEntityConfigs,
            callsignValuePrefixer: $this->callsignValuePrefixer,
        );
    }
}
