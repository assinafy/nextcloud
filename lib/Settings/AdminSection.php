<?php

declare(strict_types=1);

namespace OCA\Assinafy\Settings;

use OCA\Assinafy\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

final class AdminSection implements IIconSection
{
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return $this->l10n->t('Assinafy');
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
    }
}
