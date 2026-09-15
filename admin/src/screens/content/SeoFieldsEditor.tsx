import { useTranslation } from 'react-i18next';

import { ContentTextArea } from '@/screens/content/ContentFields';
import { MediaImageField } from '@/screens/content/MediaImageField';
import { ROBOTS, SEO_LIMITS, type SeoDraft } from '@/screens/content/seo';
import { Field } from '@/ui/Field';
import { Input } from '@/ui/Input';

export interface SeoFieldsEditorProps {
    value: SeoDraft;
    onChange: (next: SeoDraft) => void;
    disabled: boolean;
    /** The content language, which is the direction and language of what is typed here. */
    locale: string;
    direction: string;
    /** The media library collection a sharing image is uploaded into. */
    collection: string;
}

/**
 * One language's search and sharing fields, for any content that carries SEO (ADR 0032).
 *
 * Every field the platform stores is offered — title, description, robots, canonical address,
 * sharing title, description and image — so no field is kept only because the screen could not
 * show it. The text is the content language's, never the console's: an Arabic description is
 * typed right to left in an English console.
 */
export function SeoFieldsEditor({
    value,
    onChange,
    disabled,
    locale,
    direction,
    collection,
}: SeoFieldsEditorProps) {
    const { t } = useTranslation();
    const text = { dir: direction, lang: locale };

    const set = (patch: Partial<SeoDraft>): void => onChange({ ...value, ...patch });

    const count = (length: number, max: number): string =>
        t('content.seo.count', { count: length, max });

    const robotsLabel: Record<(typeof ROBOTS)[number], string> = {
        'index,follow': t('content.seo.robotsIndexFollow'),
        'noindex,follow': t('content.seo.robotsNoindexFollow'),
        'index,nofollow': t('content.seo.robotsIndexNofollow'),
        'noindex,nofollow': t('content.seo.robotsNoindexNofollow'),
    };

    return (
        <section aria-label={t('content.seo.heading')} className="flex flex-col gap-3">
            <h3 data-eyebrow>{t('content.seo.heading')}</h3>
            <p className="text-(length:--text-xs) text-(--text-muted)">{t('content.seo.intro')}</p>

            <Field
                hint={`${t('content.seo.titleHint')} ${count(value.title.length, SEO_LIMITS.title)}`}
                label={t('content.seo.title')}
            >
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        {...text}
                        aria-describedby={describedBy}
                        disabled={disabled}
                        id={id}
                        maxLength={SEO_LIMITS.title}
                        onChange={(event) => set({ title: event.target.value })}
                        value={value.title}
                    />
                )}
            </Field>

            <ContentTextArea
                {...text}
                disabled={disabled}
                hint={`${t('content.seo.descriptionHint')} ${count(value.description.length, SEO_LIMITS.description)}`}
                label={t('content.seo.description')}
                onChange={(description) => set({ description })}
                rows={2}
                value={value.description}
            />

            <Field label={t('content.seo.robots')}>
                {({ id, 'aria-describedby': describedBy }) => (
                    <select
                        aria-describedby={describedBy}
                        className="h-9 border border-(--border-default) bg-(--surface-default) px-2 text-(length:--text-sm) text-(--text-primary)"
                        disabled={disabled}
                        id={id}
                        onChange={(event) => set({ robots: event.target.value })}
                        value={value.robots}
                    >
                        <option value="">{t('content.seo.robotsDefault')}</option>
                        {ROBOTS.map((robots) => (
                            <option key={robots} value={robots}>
                                {robotsLabel[robots]}
                            </option>
                        ))}
                    </select>
                )}
            </Field>

            <Field hint={t('content.seo.canonicalHint')} label={t('content.seo.canonical')}>
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        aria-describedby={describedBy}
                        data-technical
                        dir="ltr"
                        disabled={disabled}
                        id={id}
                        maxLength={SEO_LIMITS.canonical}
                        onChange={(event) => set({ canonical_url: event.target.value })}
                        type="url"
                        value={value.canonical_url}
                    />
                )}
            </Field>

            <Field
                hint={`${t('content.seo.ogTitleHint')} ${count(value.og_title.length, SEO_LIMITS.title)}`}
                label={t('content.seo.ogTitle')}
            >
                {({ id, 'aria-describedby': describedBy }) => (
                    <Input
                        {...text}
                        aria-describedby={describedBy}
                        disabled={disabled}
                        id={id}
                        maxLength={SEO_LIMITS.title}
                        onChange={(event) => set({ og_title: event.target.value })}
                        value={value.og_title}
                    />
                )}
            </Field>

            <ContentTextArea
                {...text}
                disabled={disabled}
                hint={`${t('content.seo.ogDescriptionHint')} ${count(value.og_description.length, SEO_LIMITS.description)}`}
                label={t('content.seo.ogDescription')}
                onChange={(ogDescription) => set({ og_description: ogDescription })}
                rows={2}
                value={value.og_description}
            />

            <MediaImageField
                collection={collection}
                disabled={disabled}
                hint={t('content.seo.ogImageHint')}
                key={`og-${locale}`}
                label={t('content.seo.ogImage')}
                mediaId={value.og_media_id}
                onChange={(id) => set({ og_media_id: id })}
            />
        </section>
    );
}
