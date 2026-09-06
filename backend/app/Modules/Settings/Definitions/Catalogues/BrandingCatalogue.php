<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * Brand assets and the watermark rules applied to uploaded images.
 *
 * Every asset is a media id, never a path and never binary data. The Media module
 * owns storage, scanning, delivery and CDN resolution (ADR 0024), and a second copy
 * of any of that in Settings would be a second implementation of all four:
 *
 *     setting value -> media_id -> MediaFile -> named variant -> CDN or signed URL
 *
 * The `media` type exists for exactly this. ADR 0018 deferred branding until a
 * setting's value could be validated as an existing media record rather than as an
 * opaque string, which is what `exists:media_files,id` now does.
 */
class BrandingCatalogue implements SettingCatalogue
{
    /** Every branding asset is validated as a real, undeleted media record. */
    private const MEDIA_RULES = ['string', 'ulid', 'exists:media_files,id'];

    public function definitions(): array
    {
        return array_merge($this->assets(), $this->watermark(), $this->uploads());
    }

    /**
     * Logos and icons.
     *
     * Light and dark are separate settings rather than one with a variant, because a
     * dark-mode logo is frequently a different mark rather than the same one
     * recoloured, and an interface cannot derive one from the other.
     *
     * @return array<int, SettingDefinition>
     */
    private function assets(): array
    {
        $keys = [
            'logo_light',
            'logo_dark',
            'logo_light_en',
            'logo_dark_en',
            'favicon',
            'og_image',
        ];

        return array_map(
            fn (string $key): SettingDefinition => new SettingDefinition(
                group: 'branding',
                key: $key,
                type: SettingType::MEDIA,
                rules: self::MEDIA_RULES,
                isPublic: true,
            ),
            $keys,
        );
    }

    /**
     * Watermarking, which ADR 0024 contracts and ADR 0029 item 17 defers on an image
     * extension the container does not yet carry.
     *
     * The configuration is declared now regardless: an operator cannot supply a
     * watermark to a system with no field for one, and the processor reads these when
     * it arrives rather than inventing its own configuration then.
     *
     * @return array<int, SettingDefinition>
     */
    private function watermark(): array
    {
        return [
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_enabled',
                type: SettingType::BOOLEAN,
                default: false,
                nullable: false,
                dependsOn: ['branding.watermark_image'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_image',
                type: SettingType::MEDIA,
                rules: self::MEDIA_RULES,
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_position',
                type: SettingType::STRING,
                default: 'bottom-right',
                nullable: false,
                rules: ['string', 'in:top-left,top-right,bottom-left,bottom-right,center'],
            ),
            // Percentages rather than floats: an operator thinks in "30%", and an
            // integer cannot drift the way a stored 0.30000000000000004 can.
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_opacity',
                type: SettingType::INTEGER,
                default: 60,
                nullable: false,
                rules: ['integer', 'between:1,100'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_width_percent',
                type: SettingType::INTEGER,
                default: 20,
                nullable: false,
                rules: ['integer', 'between:1,100'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'watermark_margin_percent',
                type: SettingType::INTEGER,
                default: 2,
                nullable: false,
                rules: ['integer', 'between:0,50'],
            ),
        ];
    }

    /**
     * Upload ceilings an operator may tighten.
     *
     * These bound what the Media module accepts. They cannot loosen what the runtime
     * physically allows — `upload_max_filesize` and the proxy's own limit are
     * infrastructure and stay there — so a value above those is a ceiling that never
     * takes effect, which is why the rule caps it.
     *
     * @return array<int, SettingDefinition>
     */
    private function uploads(): array
    {
        return [
            new SettingDefinition(
                group: 'branding',
                key: 'max_upload_kilobytes',
                type: SettingType::INTEGER,
                default: 10240,
                nullable: false,
                rules: ['integer', 'between:64,102400'],
            ),
            new SettingDefinition(
                group: 'branding',
                key: 'max_image_dimension',
                type: SettingType::INTEGER,
                default: 8000,
                nullable: false,
                rules: ['integer', 'between:100,20000'],
            ),
        ];
    }
}
