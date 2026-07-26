<?php

namespace justinholtweb\archive\collectors;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Address;
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
 * Collects addresses.
 *
 * Addresses owned by a user are personal data, so they're held back unless user export has
 * been allowed. Addresses owned by anything else — an address field on an entry, say — are
 * ordinary content and always travel.
 */
class AddressCollector extends BaseCollector
{
    public static function key(): string
    {
        return 'addresses';
    }

    public static function label(): string
    {
        return Craft::t('archive', 'Addresses');
    }

    protected function isLocalized(): bool
    {
        return false;
    }

    protected function query(ExportConfig $config, Site $site): ?ElementQueryInterface
    {
        return Address::find()->status(null);
    }

    protected function shouldCollect(ElementInterface $element, ExportContext $context): bool
    {
        /** @var Address $element */
        if (!$this->owner($element) instanceof User) {
            return true;
        }

        if (Plugin::getInstance()->getSettings()->allowUserExport) {
            return true;
        }

        $context->warn(Craft::t('archive', 'Skipped addresses belonging to user accounts, because user export is switched off.'));

        return false;
    }

    protected function attributes(ElementInterface $element, ExportContext $context): array
    {
        /** @var Address $element */
        $owner = $this->owner($element);

        $attributes = ValueHelper::compact([
            'label' => $element->title,
            'fullName' => $element->fullName,
            'firstName' => $element->firstName,
            'lastName' => $element->lastName,
            'organization' => $element->organization,
            'organizationTaxId' => $element->organizationTaxId,
            'countryCode' => $element->countryCode,
            'administrativeArea' => $element->administrativeArea,
            'locality' => $element->locality,
            'dependentLocality' => $element->dependentLocality,
            'postalCode' => $element->postalCode,
            'sortingCode' => $element->sortingCode,
            'addressLine1' => $element->addressLine1,
            'addressLine2' => $element->addressLine2,
            'addressLine3' => $element->addressLine3,
            'latitude' => $element->latitude,
            'longitude' => $element->longitude,
        ]);

        if ($owner !== null) {
            $attributes['owner'] = RefHelper::ref($owner, $context);
        }

        return $attributes;
    }

    private function owner(Address $address): ?ElementInterface
    {
        try {
            return $address->getOwner();
        } catch (Throwable) {
            return null;
        }
    }
}
