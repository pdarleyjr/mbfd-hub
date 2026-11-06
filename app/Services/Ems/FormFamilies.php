<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SourceForm;

/**
 * The four medical inventory forms (docs/ems/four-inventory-forms.md section 1). This is the one place that says
 * which lists belong to which form; the API hands them to the app and nothing else hard-codes them.
 *
 * A request belongs to exactly one family (`ems_inventory_requests.form_family`). Rows written before the families
 * existed, and rows written by a rolled-back image, have no family and are ALS Transport.
 *
 * @phpstan-type Family array{key: string, title: string, short_title: string, form_key: string, description: string, bag_keys: list<string>}
 */
final class FormFamilies
{
    public const ALS_TRANSPORT = 'als-transport';

    public const ALS_NON_TRANSPORT = 'als-non-transport';

    public const FIRE_BOAT_6 = 'fire-boat-6';

    public const BLS_DETAIL = 'bls-detail';

    /** The deep-link key the single Medical Inventory Request used. It still opens ALS Transport. */
    public const LEGACY_FORM_KEY = 'medical-inventory-request';

    public const DEFAULT = self::ALS_TRANSPORT;

    /** @return list<Family> in display order */
    public static function all(): array
    {
        return [
            [
                'key' => self::ALS_TRANSPORT,
                'title' => 'ALS Transport Inventory Form',
                'short_title' => 'ALS Transport',
                'form_key' => 'als-transport-inventory',
                'description' => 'Airway Bag, ALS Med Bag or Box, ALS Transport Vehicle and Handtevy. Request only what you need.',
                'bag_keys' => [SourceForm::AIRWAY, SourceForm::ALS, SourceForm::TRANSPORT, SourceForm::HANDTEVY],
            ],
            [
                'key' => self::ALS_NON_TRANSPORT,
                'title' => 'ALS Non Transport Inventory Form',
                'short_title' => 'ALS Non Transport',
                'form_key' => 'als-non-transport-inventory',
                'description' => 'ALS Non Transport bag and Handtevy. Request only what you need.',
                'bag_keys' => [SourceForm::ALS_NON_TRANSPORT, SourceForm::HANDTEVY],
            ],
            [
                'key' => self::FIRE_BOAT_6,
                'title' => 'Fire Boat 6 Medical Inventory Form',
                'short_title' => 'Fire Boat 6',
                'form_key' => 'fire-boat-6-medical-inventory',
                'description' => 'ALS supplies for Fire Boat 6. Request only what you need.',
                'bag_keys' => [SourceForm::FIRE_BOAT_6_ALS],
            ],
            [
                'key' => self::BLS_DETAIL,
                'title' => 'BLS Detail Medical Inventory Form',
                'short_title' => 'BLS Detail',
                'form_key' => 'bls-detail-medical-inventory',
                'description' => 'BLS bag for a detail or special event. Request only what you need.',
                'bag_keys' => [SourceForm::BLS_DETAIL_BAG],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /** @return Family|null */
    public static function find(?string $key): ?array
    {
        foreach (self::all() as $family) {
            if ($family['key'] === $key) {
                return $family;
            }
        }

        return null;
    }

    /**
     * The family a deep-link `form=` value opens: a family's own key, or the legacy key (ALS Transport).
     *
     * @return Family|null
     */
    public static function fromFormKey(?string $formKey): ?array
    {
        if ($formKey === null || $formKey === '') {
            return null;
        }
        if ($formKey === self::LEGACY_FORM_KEY) {
            return self::find(self::ALS_TRANSPORT);
        }
        foreach (self::all() as $family) {
            if ($family['form_key'] === $formKey) {
                return $family;
            }
        }

        return null;
    }

    /**
     * The family a stored value means. Nothing stored (an old row, a rolled-back image) is ALS Transport, and so is a
     * value this release does not know, so a reader never has to handle a missing family.
     *
     * @return Family
     */
    public static function resolve(?string $stored): array
    {
        return self::find($stored) ?? self::find(self::DEFAULT) ?? self::all()[0];
    }

    /** @return list<string> the source list keys of the family, in tab order; empty for an unknown family */
    public static function bagsFor(?string $key): array
    {
        return self::find($key)['bag_keys'] ?? [];
    }

    /** @return list<string> every source list key any family uses, each once */
    public static function allBagKeys(): array
    {
        return array_values(array_unique(array_merge(...array_column(self::all(), 'bag_keys'))));
    }

    /**
     * What a list shows about the family: key, title, short title, deep-link key and description.
     *
     * @param  Family  $family
     * @return array{key: string, title: string, short_title: string, form_key: string, description: string}
     */
    public static function summary(array $family): array
    {
        return [
            'key' => $family['key'], 'title' => $family['title'], 'short_title' => $family['short_title'],
            'form_key' => $family['form_key'], 'description' => $family['description'],
        ];
    }
}
