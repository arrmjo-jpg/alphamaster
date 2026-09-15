<?php

declare(strict_types=1);

namespace App\Modules\Pages\Enums;

use App\Modules\Authorization\Contracts\PermissionDefinition;

/**
 * The permissions the Pages module declares (ADR 0052, ADR 0055 §10).
 *
 * Publishing is its own permission: writing a page and making it public are different
 * decisions, and a site may want the second held by fewer people.
 */
enum PagePermission: string implements PermissionDefinition
{
    case VIEW = 'pages.view';
    case CREATE = 'pages.create';
    case UPDATE = 'pages.update';
    case PUBLISH = 'pages.publish';
    case DELETE = 'pages.delete';

    public function key(): string
    {
        return $this->value;
    }

    public function module(): string
    {
        return 'pages';
    }
}
