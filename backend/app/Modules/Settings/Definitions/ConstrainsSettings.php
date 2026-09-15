<?php

declare(strict_types=1);

namespace App\Modules\Settings\Definitions;

/**
 * A catalogue whose settings constrain one another.
 *
 * A definition's rules see one value. Some limits are only meaningful in relation to
 * another setting — a minimum that must not exceed its maximum — and no rule on either
 * definition alone can say so. A catalogue that declares both says it here, and a write that
 * would leave the group violating it is refused as a whole.
 */
interface ConstrainsSettings extends SettingCatalogue
{
    /**
     * The constraints the values would violate.
     *
     * Receives the typed values of the group being written, as they would be after the write,
     * keyed by `group.key`. A reference absent from the map belongs to another group and is
     * not being written, so a constraint involving it does not apply to this write.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string> a message for each violated constraint, keyed by the
     *                               reference the operator should correct
     */
    public function violations(array $values): array;
}
