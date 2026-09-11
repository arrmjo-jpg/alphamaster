import { Globe, Monitor, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { isSupportedLocale, SUPPORTED_LOCALES, type SupportedLocale } from '@/i18n';
import { Menu, MenuRadioList, MenuSeparator, type MenuRadioOption } from '@/ui/Menu';
import { SegmentedControl } from '@/ui/SegmentedControl';

import { useDensity, type Density } from './DensityProvider';
import { useDirection } from './DirectionProvider';
import { flagForLanguage } from './languageRegion';
import { useTheme, type ThemePreference } from './ThemeProvider';

/**
 * The viewer's own preferences, as two menus in the top bar.
 *
 * They were seven adjacent buttons — three themes, three densities, one per language —
 * which read as a control panel bolted to the chrome and grew every time the platform
 * activated a language. Folded into menus they answer the two questions the top bar is
 * for: what am I reading this in, and how does it look. Neither is navigation and
 * neither configures the platform, which is why they are here and not in Settings.
 *
 * Theme and density are one menu rather than two. Both are appearance, both are stored
 * per browser rather than per account, and separating them bought a second trigger for
 * no distinction an operator makes.
 */

const LOCALE_LABELS: Record<SupportedLocale, string> = { en: 'English', ar: 'العربية' };

/**
 * What sits before a language's name.
 *
 * A flag when the platform has said which region the entry is for, and a globe when it
 * has not. Never a flag guessed from the language itself — see `languageRegion.ts` for
 * why that is a different and much worse thing to do.
 *
 * `aria-hidden` on both: the row is already named by the language, and a screen reader
 * announcing "Jordan" beside "العربية" would be reading a country to someone who was
 * offered a language.
 */
function LanguageIndicator({ flag }: { flag: string | null }) {
    if (flag === null) {
        return <Globe aria-hidden className="size-4 shrink-0" />;
    }

    return (
        <span aria-hidden className="w-4 shrink-0 text-center leading-none">
            {flag}
        </span>
    );
}

const THEME_ICONS: Record<ThemePreference, typeof Sun> = {
    light: Sun,
    dark: Moon,
    system: Monitor,
};

/**
 * Theme and density, behind one trigger that shows the theme in force.
 *
 * The trigger's icon is the current preference rather than a fixed glyph, so the menu
 * reports its own state while closed — which is the thing a set of visible segments
 * did for free and a menu has to be given deliberately.
 */
export function AppearanceControl() {
    const { t } = useTranslation();
    const { preference, setPreference } = useTheme();
    const { density, setDensity } = useDensity();

    const TriggerIcon = THEME_ICONS[preference];

    return (
        <Menu
            label={t('appearance.label')}
            trigger={
                <>
                    <TriggerIcon aria-hidden className="size-4 shrink-0" />
                    <span className="hidden lg:inline">{t('appearance.label')}</span>
                </>
            }
        >
            <MenuRadioList<ThemePreference>
                label={t('theme.label')}
                onSelect={setPreference}
                options={[
                    { value: 'light', label: t('theme.light'), icon: <Sun className="size-3.5" /> },
                    { value: 'dark', label: t('theme.dark'), icon: <Moon className="size-3.5" /> },
                    {
                        value: 'system',
                        label: t('theme.system'),
                        icon: <Monitor className="size-3.5" />,
                    },
                ]}
                value={preference}
            />

            <MenuSeparator />

            <MenuRadioList<Density>
                label={t('density.label')}
                onSelect={setDensity}
                options={[
                    { value: 'compact', label: t('density.compact') },
                    { value: 'comfortable', label: t('density.comfortable') },
                    { value: 'spacious', label: t('density.spacious') },
                ]}
                value={density}
            />
        </Menu>
    );
}

/**
 * Light, dark or system, as three visible segments.
 *
 * This is the sign-in cover's control and only its control. The cover is one centred
 * panel with room to spare, there is no density on it to fold a theme in with, and a
 * person who cannot read the screen they are being asked to sign in on should not have
 * to discover a menu to fix it. Inside the console the same three options are in the
 * Appearance menu, because there the constraint is a top bar shared with everything
 * else — a different problem, and so a different control.
 */
export function ThemeControl() {
    const { t } = useTranslation();
    const { preference, setPreference } = useTheme();

    return (
        <SegmentedControl<ThemePreference>
            label={t('theme.label')}
            onChange={setPreference}
            options={[
                { value: 'light', label: t('theme.light'), icon: <Sun className="size-3.5" /> },
                { value: 'dark', label: t('theme.dark'), icon: <Moon className="size-3.5" /> },
                {
                    value: 'system',
                    label: t('theme.system'),
                    icon: <Monitor className="size-3.5" />,
                },
            ]}
            value={preference}
        />
    );
}

export interface LocaleControlProps {
    /**
     * The choices, for a test that needs more of them than the bundle ships.
     *
     * Absent in the application: the real list is the platform's, read through
     * DirectionProvider. Present here because the property worth testing is that a
     * long list stays usable, and that cannot be exercised against two.
     */
    options?: readonly MenuRadioOption<string>[];
    /**
     * Which ground the trigger sits on. The top bar is chrome; the sign-in cover is an
     * ordinary panel, and a chrome-coloured trigger there is invisible in one theme.
     */
    tone?: 'chrome' | 'surface';
}

/**
 * The languages this console can be read in, as the platform currently serves them.
 *
 * Driven by `/languages` rather than by a list written here: a language an
 * administrator has deactivated is one the platform no longer serves, and offering it
 * would be offering to render the console in a language the API will not answer in.
 * The label is the platform's own `native_name` — a language names itself.
 *
 * A flag is shown when — and only when — the platform has said which region a language
 * entry is for. That comes from an explicit `region` field if the API ever grows one,
 * or from the region subtag of the code itself: `ar-JO` is an assertion about a region
 * and gets 🇯🇴, while a bare `ar` is a language and gets a globe. Nothing here maps a
 * language to a country, because that mapping does not exist — see `languageRegion.ts`.
 *
 * Today every row shows the globe, because the two languages the platform serves are
 * coded `en` and `ar` with no region. That is the honest output rather than a failure:
 * the moment an entry carries a region, its flag appears with no change to this file.
 *
 * The English `name` is folded into the filter's haystack, so searching "arabic" finds
 * العربية without either name being displayed twice.
 *
 * The static list is the fallback for the one case where there is no answer: the API is
 * unreachable, and a switcher with nothing in it is worse than one offering what the
 * bundle can certainly render.
 */
export function LocaleControl({ options: injected, tone }: LocaleControlProps = {}) {
    const { t } = useTranslation();
    const { locale, available, setLocale } = useDirection();

    const choices: readonly MenuRadioOption<string>[] =
        injected ??
        (available.length > 0
            ? available
                  .filter((language) => isSupportedLocale(language.code))
                  .map((language) => ({
                      value: language.code,
                      label: language.native_name,
                      hint: language.code.toLocaleUpperCase(),
                      keywords: language.name,
                  }))
            : SUPPORTED_LOCALES.map((code) => ({
                  value: code,
                  label: LOCALE_LABELS[code],
                  hint: code.toLocaleUpperCase(),
              })));

    // The indicator is attached here rather than inside each branch above, so the
    // rule is applied once and applies equally to the platform's list and to an
    // injected one. Each row's own code is the only input.
    const options: readonly MenuRadioOption<string>[] = choices.map((choice) => ({
        ...choice,
        icon: <LanguageIndicator flag={flagForLanguage({ code: choice.value })} />,
    }));

    const current = options.find((option) => option.value === locale);

    // Derived from the platform's own entry rather than from the option, so an
    // injected test fixture and the real list behave the same way.
    const currentFlag = flagForLanguage({ code: current?.value ?? locale });

    // One option is not a choice. It still has to be announced, though, or an operator
    // is left wondering where the switcher went.
    if (options.length <= 1) {
        return (
            <p className="px-2 text-(length:--text-sm) text-(--text-on-chrome-muted)">
                {t('language.only', { name: options[0]?.label ?? locale })}
            </p>
        );
    }

    return (
        <Menu
            label={t('language.label')}
            trigger={
                <>
                    {/* The same indicator the current row carries, so a closed menu
                        and its open list agree about what is selected. */}
                    <LanguageIndicator flag={currentFlag} />
                    <span className="hidden max-w-28 truncate sm:inline">
                        {current?.label ?? locale}
                    </span>
                </>
            }
            {...(tone === undefined ? {} : { tone })}
        >
            <MenuRadioList<string>
                emptyLabel={t('language.noMatches')}
                label={t('language.label')}
                onSelect={(next) => {
                    // A real guard rather than a cast: the injected list exists for
                    // tests and may name a locale this bundle has no catalogue for,
                    // and switching to one would render an interface of raw keys.
                    if (isSupportedLocale(next)) {
                        setLocale(next);
                    }
                }}
                options={options}
                searchLabel={t('language.search')}
                searchPlaceholder={t('language.searchPlaceholder')}
                value={locale}
            />
        </Menu>
    );
}
