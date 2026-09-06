<?php

declare(strict_types=1);

namespace App\Modules\Settings\Resources;

use App\Modules\Settings\Definitions\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One setting definition, as an administrative interface needs it.
 *
 * This is the metadata a client cannot infer from a value: what type it is, whether
 * it may be edited, whether it is a secret, whether it varies by locale, and what
 * else must be configured before it takes effect. Without it an interface has to
 * hard-code a form per setting, which is the thing the registry exists to avoid.
 *
 * Technical members keep their names and gain `_label` siblings (ADR 0031). No
 * value appears here at all — a definition describes a setting, and reading what
 * one is set to is a different endpoint with a different shape.
 *
 * @property-read SettingDefinition $resource
 */
class SettingDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->resource;

        return [
            'key' => $definition->reference(),
            'group' => $definition->group,
            'name' => $definition->key,

            'label' => $definition->label(),
            'help' => $definition->help(),

            'type' => $definition->type->value,
            'type_label' => $definition->type->label(),

            'nullable' => $definition->nullable,
            'editable' => $definition->editable,
            'is_secret' => $definition->isSecret,
            'is_public' => $definition->isPublic,
            'is_localized' => $definition->isLocalized,

            // The default a fresh installation received. Never sent for a secret,
            // which by construction has none (ADR 0018).
            'default' => $definition->isSecret ? null : $definition->default,

            // What must be configured before this one takes effect, so an interface
            // can say so rather than letting an operator switch on something that
            // silently does nothing.
            'depends_on' => $definition->dependsOn,

            // The permission required beyond settings.update, where this value is
            // sensitive enough to warrant its own.
            'permission' => $definition->permission,

            'deprecated' => $definition->isDeprecated(),
        ];
    }
}
