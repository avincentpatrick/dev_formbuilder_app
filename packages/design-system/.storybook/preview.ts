import type { Preview } from '@storybook/vue3';

// Generated tokens + the theme/personalization re-pointing layer — the same CSS every app loads.
import '../dist/tokens.css';
import '../src/theme/theme-overrides.css';
// The optional dyslexia face, so the §2.9 personalization stories render the real font rather than
// the fallback stack. (The admin app imports this the same way; the guest runtime deliberately does not.)
import '../src/theme/fonts.css';

const preview: Preview = {
    parameters: {
        controls: { matchers: { color: /(background|color)$/i } },
    },
    // Toolbar switch for data-theme-mode, so reviewers can eyeball light vs dark on any story.
    globalTypes: {
        themeMode: {
            description: 'Theme mode (data-theme-mode on <html>)',
            defaultValue: 'light',
            toolbar: {
                title: 'Theme',
                icon: 'circlehollow',
                items: [
                    { value: 'light', title: 'Light' },
                    { value: 'dark', title: 'Dark' },
                ],
                dynamicTitle: true,
            },
        },
    },
    decorators: [
        (story, context) => {
            document.documentElement.setAttribute(
                'data-theme-mode',
                context.globals.themeMode ?? 'light',
            );

            // The §2.9 personalization axes, opted into per story via
            // `parameters: { personalization: { accent, fontSize, dyslexia } }`.
            //
            // CRITICAL: set OR CLEAR every axis on EVERY story, never conditionally set. All stories
            // share one preview iframe and therefore one <html>, so an attribute left behind by a
            // personalization story would silently apply to whatever renders next — and the a11y
            // run would then scan an unrelated component under teal or extra_large and fail in a way
            // that looks random.
            //
            // These are :root[…] selectors, so they only match <html>. A decorator that wrapped the
            // story in a <div> could not demonstrate them at all.
            const personalization = (context.parameters.personalization ?? {}) as {
                accent?: 'blueprint' | 'teal';
                fontSize?: 'standard' | 'large' | 'extra_large';
                dyslexia?: boolean;
            };
            const setRootAttribute = (attribute: string, value: string | null): void => {
                if (value === null) document.documentElement.removeAttribute(attribute);
                else document.documentElement.setAttribute(attribute, value);
            };

            setRootAttribute('data-accent', personalization.accent === 'teal' ? 'teal' : null);
            setRootAttribute(
                'data-font-size',
                personalization.fontSize && personalization.fontSize !== 'standard'
                    ? personalization.fontSize
                    : null,
            );
            setRootAttribute('data-dyslexia-font', personalization.dyslexia ? 'true' : null);
            // Render the canvas the way the app does — body font on the themed canvas ground —
            // so axe measures real text-on-background contrast, not black-on-white defaults.
            document.body.style.fontFamily = 'var(--mds-font-family-body)';
            document.body.style.color = 'var(--mds-color-text-body)';
            document.body.style.backgroundColor = 'var(--mds-color-bg-canvas)';
            document.body.style.padding = 'var(--mds-space-6)';
            return story();
        },
    ],
};

export default preview;
