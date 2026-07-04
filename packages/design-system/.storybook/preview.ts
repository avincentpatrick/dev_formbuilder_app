import type { Preview } from '@storybook/vue3';

// Generated tokens + the theme/personalization re-pointing layer — the same CSS every app loads.
import '../dist/tokens.css';
import '../src/theme/theme-overrides.css';

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
            return story();
        },
    ],
};

export default preview;
