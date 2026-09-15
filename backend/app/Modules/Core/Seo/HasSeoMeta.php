<?php

declare(strict_types=1);

namespace App\Modules\Core\Seo;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that carries per-locale SEO metadata (ADR 0032).
 *
 * The metadata lives in `seo_meta`, keyed by the model's morph class and key, so a module adds
 * SEO to its own model with this trait and `SeoMetaStore` — no table, no resolver, no endpoint
 * of its own.
 *
 * Deleting the model deletes its metadata. A polymorphic row has no foreign key to cascade, so
 * without this every owner would have to remember, and the first that forgot would leave rows
 * describing content that no longer exists. A soft delete keeps them: the content can come back.
 */
trait HasSeoMeta
{
    public static function bootHasSeoMeta(): void
    {
        static::deleting(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            app(SeoMetaStore::class)->forget($model);
        });
    }
}
