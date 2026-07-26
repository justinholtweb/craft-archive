<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\models\Site;
use justinholtweb\archive\helpers\RefHelper;
use justinholtweb\archive\helpers\ValueHelper;
use justinholtweb\archive\models\ExportConfig;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;
use Throwable;

/**
 * Collects user accounts — but only when they've been explicitly allowed.
 *
 * A bundle is a downloadable ZIP, and user records are personal data, so this collector
 * doesn't even appear on the export screen until the setting is switched on. Password
 * hashes are never exported under any setting: they're the one thing a migration has no
 * business carrying, and every sensible target platform will force a reset anyway.
 */
class UserCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'users';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Users');
    }

    public function isAvailable(): bool
    {
        return Plugin::getInstance()->getSettings()->allowUserExport;
    }

    protected function isLocalized(): bool
    {
        return false;
    }

    protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface
    {
        return User::find()
            ->status($config->includeDisabled ? null : ['active', 'pending']);
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var User $element */
        $attributes = ValueHelper::compact([
            'username' => $element->username,
            'email' => $element->email,
            'fullName' => $element->getFullName(),
            'firstName' => $element->firstName,
            'lastName' => $element->lastName,
            'admin' => $element->admin,
            'pending' => $element->pending,
            'locked' => $element->locked,
            'suspended' => $element->suspended,
            'lastLoginDate' => ValueHelper::date($element->lastLoginDate),
            'groups' => $this->groups($element),
            'preferences' => ValueHelper::jsonSafe($element->getPreferences()),
        ]);

        $photo = $this->photo($element, $context);
        if ($photo !== null) {
            $attributes['photo'] = $photo;
        }

        return $attributes;
    }

    /**
     * @return string[] Group handles.
     */
    private function groups(User $user): array
    {
        try {
            return array_map(fn($group) => $group->handle, $user->getGroups());
        } catch (Throwable) {
            return [];
        }
    }

    private function photo(User $user, ExportContext $context): ?array
    {
        try {
            $photo = $user->getPhoto();
        } catch (Throwable) {
            return null;
        }

        return $photo !== null ? RefHelper::ref($photo, $context) : null;
    }
}
