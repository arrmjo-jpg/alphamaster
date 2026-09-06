<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

use Illuminate\Support\Facades\Validator;

/**
 * Applies the rules a definition declares (ADR 0018, ADR 0040 as extended).
 *
 * These rules were declared from Phase 16A onwards, published through the definitions
 * endpoint, and enforced nowhere. `UpdateGroupSettingsRequest` validated the shape of the
 * payload and the service enforced the declared *type*; between the two, nothing ever ran
 * `between:1,100` on a watermark opacity or `exists:media_files,id` on a branding image.
 * A form built from the catalogue would have refused what the API accepted.
 *
 * One class rather than a check at each call site, because there are now three writers —
 * an ordinary update, a rollback, and a configuration restore — and three copies of a
 * validation rule is three chances for one of them to be the lenient one.
 */
class DefinitionValidator
{
    /**
     * The messages a value fails on, empty when it satisfies the declaration.
     *
     * Takes the value already cast to its declared type, so the rules see an integer
     * where the declaration says integer. Validating the stored string instead would
     * make `integer` fail on "60" and `between` compare string lengths — rules that
     * appear to work and mean something else.
     *
     * @return array<int, string>
     */
    public function messages(SettingDefinition $definition, mixed $typed): array
    {
        if ($definition->rules === []) {
            return [];
        }

        // Null is the absence of a value, not a value that could satisfy a rule.
        // Running `integer` or `between` against it would refuse every setting an
        // operator tries to clear.
        //
        // Whether clearing is allowed at all is what `nullable` describes, and that is
        // deliberately not enforced here. It has never been enforced on any write path,
        // `serializeValue` treats null as an explicit unset throughout, and tests assert
        // that a non-nullable setting can be cleared. Changing it is a behaviour change
        // in its own right, not a consequence of running the declared rules.
        if ($typed === null) {
            return [];
        }

        $validator = Validator::make(['value' => $typed], ['value' => $definition->rules]);

        if ($validator->passes()) {
            return [];
        }

        /** @var array<int, string> $messages */
        $messages = $validator->errors()->get('value');

        // The attribute is the reference rather than "value", so an operator writing
        // twenty settings at once is told which one was refused.
        return array_map(
            static fn (string $message): string => str_replace('value', $definition->reference(), $message),
            $messages,
        );
    }

    public function violates(SettingDefinition $definition, mixed $typed): bool
    {
        return $this->messages($definition, $typed) !== [];
    }
}
