<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\OpenApi;

final readonly class Options
{
    public function __construct(
        private string $title,
        private string $description = '',
        private string $version = '',
        private bool $oAuthEnabled = false,
        private ?string $oAuthType = null,
        private ?string $oAuthFlow = null,
        private ?string $oAuthTokenUrl = null,
        private ?string $oAuthAuthorizationUrl = null,
        private ?string $oAuthRefreshUrl = null,
        private array $oAuthScopes = [],
        private array $apiKeys = [],
        private ?string $contactName = null,
        private ?string $contactUrl = null,
        private ?string $contactEmail = null,
        private ?string $termsOfService = null,
        private ?string $licenseName = null,
        private ?string $licenseUrl = null,
        private bool $overrideResponses = true,
        private bool $persistAuthorization = false,
    ) {
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getOAuthEnabled(): bool
    {
        return $this->oAuthEnabled;
    }

    public function getOAuthType(): ?string
    {
        return $this->oAuthType;
    }

    public function getOAuthFlow(): ?string
    {
        return $this->oAuthFlow;
    }

    public function getOAuthTokenUrl(): ?string
    {
        return $this->oAuthTokenUrl;
    }

    public function getOAuthAuthorizationUrl(): ?string
    {
        return $this->oAuthAuthorizationUrl;
    }

    public function getOAuthRefreshUrl(): ?string
    {
        return $this->oAuthRefreshUrl;
    }

    public function getOAuthScopes(): array
    {
        return $this->oAuthScopes;
    }

    public function getApiKeys(): array
    {
        return $this->apiKeys;
    }

    public function getContactName(): ?string
    {
        return $this->contactName;
    }

    public function getContactUrl(): ?string
    {
        return $this->contactUrl;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function getTermsOfService(): ?string
    {
        return $this->termsOfService;
    }

    public function getLicenseName(): ?string
    {
        return $this->licenseName;
    }

    public function getLicenseUrl(): ?string
    {
        return $this->licenseUrl;
    }

    public function getOverrideResponses(): bool
    {
        return $this->overrideResponses;
    }

    public function isPersistAuthorization(): bool
    {
        return $this->persistAuthorization;
    }
}
