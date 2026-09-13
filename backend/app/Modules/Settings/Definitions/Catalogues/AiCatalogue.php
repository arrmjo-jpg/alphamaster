<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions\Catalogues;

use App\Modules\Settings\Definitions\SettingCatalogue;
use App\Modules\Settings\Definitions\SettingDefinition;
use App\Modules\Settings\Enums\SettingType;

/**
 * What an operator decides about AI, and nothing else.
 *
 * Two settings, and the list is short on purpose. ADR 0044 says what an operator
 * controls — "whether the capability is active, which vendor and model answer, the
 * per-task ceiling on output size, and the timeout" — and three of those four are
 * answered by the provider itself, in the AI control centre: whether it is enabled,
 * its key, and its model. The model used to be a setting here, and a single global
 * model was sent to whichever vendor answered — an OpenAI model name to Anthropic. It
 * now belongs to the provider it works with.
 *
 * What is deliberately absent:
 *
 * **A prompt.** It is code (ADR 0044 §6). Making it editable would put the platform's
 * behaviour outside its own version control and hand whoever holds `settings.update`
 * the ability to change what the platform tells a vendor about its own content.
 *
 * **A temperature.** Fixed per task. A translation wants near-determinism; a task
 * that wants otherwise brings its own value, not an operator's.
 *
 * **An `ai.enabled` switch.** Whether AI is available is derivable — an active
 * provider that holds credentials — and a setting that restates a fact the platform
 * can read is a second answer waiting to disagree with the first.
 */
class AiCatalogue implements SettingCatalogue
{
    public function definitions(): array
    {
        return [
            // The ceiling on what may come back. A translation of a label is short;
            // without a ceiling a model that misreads the instruction can return an
            // essay, and the operator pays for every token of it.
            new SettingDefinition(
                group: 'ai',
                key: 'max_output_tokens',
                type: SettingType::INTEGER,
                default: 512,
                nullable: false,
                rules: ['integer', 'between:32,4096'],
            ),

            // Its own timeout rather than `operations.provider_timeout_seconds`. That
            // one is the ceiling on a message or a challenge — calls that answer in
            // well under a second — and applying its ten-second default here would fail
            // every generation that was working exactly as intended.
            new SettingDefinition(
                group: 'ai',
                key: 'timeout_seconds',
                type: SettingType::INTEGER,
                default: 60,
                nullable: false,
                rules: ['integer', 'between:5,300'],
            ),
        ];
    }
}
