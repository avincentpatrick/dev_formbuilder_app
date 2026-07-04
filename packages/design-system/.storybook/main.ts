import type { StorybookConfig } from '@storybook/vue3-vite';
import vue from '@vitejs/plugin-vue';

const config: StorybookConfig = {
    stories: ['../src/**/*.stories.@(ts|tsx)'],
    addons: ['@storybook/addon-essentials', '@storybook/addon-a11y'],
    framework: {
        name: '@storybook/vue3-vite',
        options: {},
    },
    // Explicitly register the Vue SFC plugin (the builder does not add it reliably under an
    // isolated install), so .vue components compile in both dev and static build.
    viteFinal: async (viteConfig) => {
        viteConfig.plugins = viteConfig.plugins ?? [];
        viteConfig.plugins.push(vue());
        return viteConfig;
    },
};

export default config;
