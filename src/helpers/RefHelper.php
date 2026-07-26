<?php

namespace justinholtweb\archive\helpers;

use craft\base\ElementInterface;
use craft\elements\Address;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\archive\models\ExportContext;
use justinholtweb\archive\Plugin;

/**
 * Builds the element reference shape every pointer in a bundle uses.
 */
abstract class RefHelper
{
    /**
     * Core element classes mapped to the short type slugs bundles use.
     */
    private const TYPE_SLUGS = [
        Entry::class => 'entry',
        Category::class => 'category',
        Tag::class => 'tag',
        Asset::class => 'asset',
        User::class => 'user',
        Address::class => 'address',
        GlobalSet::class => 'globalSet',
    ];

    /**
     * A short, stable slug for an element type — 'entry', 'asset', and so on. Element
     * types Archive doesn't know fall back to their class name.
     */
    public static function type(ElementInterface|string $element): string
    {
        $class = is_string($element) ? $element : $element::class;

        if (isset(self::TYPE_SLUGS[$class])) {
            return self::TYPE_SLUGS[$class];
        }

        foreach (self::TYPE_SLUGS as $known => $slug) {
            if (is_subclass_of($class, $known)) {
                return $slug;
            }
        }

        return $class;
    }

    /**
     * A reference to another element. Assets go through the asset bundler so the ref can
     * say whether the file itself made it into the bundle.
     */
    public static function ref(ElementInterface $element, ?ExportContext $context = null): array
    {
        if ($element instanceof Asset && $context !== null) {
            return Plugin::getInstance()->assets->ref($element, $context);
        }

        $ref = [
            'uid' => $element->uid,
            'id' => $element->id,
            'type' => self::type($element),
            'title' => (string)$element,
        ];

        if ($element instanceof User) {
            $ref['username'] = $element->username;
            $ref['title'] = $element->getName();
        }

        if ($element->slug !== null) {
            $ref['slug'] = $element->slug;
        }

        $url = $element->getUrl();
        if ($url !== null) {
            $ref['url'] = $url;
        }

        return $ref;
    }

    /**
     * A reference to an author, which deliberately carries no email address — user data
     * only leaves the site when users are explicitly exported.
     */
    public static function userRef(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return array_filter([
            'uid' => $user->uid,
            'id' => $user->id,
            'username' => $user->username,
            'name' => $user->getName(),
        ], fn($value) => $value !== null && $value !== '');
    }
}
