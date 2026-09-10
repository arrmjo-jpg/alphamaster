<?php

declare(strict_types=1);

namespace App\Modules\Settings\Enums;

/**
 * What changing a setting actually does today.
 *
 * An audit of the catalogue found that a third of the settings changed nothing
 * anywhere: they were declared for a public website that has not been built, or ahead
 * of a capability the platform does not have, and a screen that presents those beside
 * the ones that close a login after five failures is lying by omission. An operator
 * reasonably assumes that a control which can be changed does something.
 *
 * So every definition declares its reach and the console says so. This is not a
 * disabled state — the values are real, they persist, they are exported and restored
 * with the rest of the configuration, and they will be read the moment the thing that
 * reads them exists. It is a statement about *who* reads them, made once, where the
 * setting is declared, rather than remembered by whoever writes the next screen.
 *
 * The point of the enum rather than a free-text note is that the set is small and
 * closed. A setting is read by this platform, or served to something else, or waiting
 * for something that does not exist yet. There is no fourth answer that is honest.
 */
enum SettingReach: string
{
    /**
     * The platform reads it and behaves differently. The default, and the only one
     * that needs no explanation on screen.
     */
    case PLATFORM = 'platform';

    /**
     * Published through the settings API for a client to read, and nothing on this
     * platform behaves differently. A timezone the front end formats with, say.
     */
    case PUBLISHED = 'published';

    /**
     * Declared ahead of the thing that would read it. The public website, the image
     * pipeline, the retry policy — each is a decided capability that is not built, and
     * a value stored for it changes nothing until it is.
     */
    case AWAITING = 'awaiting';

    /**
     * The sentence shown beside the control, as a translation key.
     */
    public function noticeKey(): ?string
    {
        return match ($this) {
            self::PLATFORM => null,
            self::PUBLISHED => 'setting.reach.published',
            self::AWAITING => 'setting.reach.awaiting',
        };
    }
}
