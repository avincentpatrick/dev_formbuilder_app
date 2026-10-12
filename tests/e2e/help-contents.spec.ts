import { expect, test } from '@playwright/test';

/**
 * The Help page keeps its contents within reach (M164), the user's comment on `M163`: *"i am having a hard time
 * scrolling up and down checking the contents, is there another way that contents are always seen? can we have the
 * contents on the left side?"*.
 *
 * ⛔ WHY A REAL BROWSER. "Stays on screen" is `position: sticky`, and sticky fails SILENTLY: an `overflow` on any box
 * between it and its scroll region pins it to the page instead, and every unit test still passes because none of them
 * lays anything out. The page scrolls inside the shell's `.app-shell__content`, not the window — so the only proof is
 * to scroll that region to the last chapter and look.
 */

const CONTENTS = '#manual-contents';
const LAST_CHAPTER = '#publish-checks';
const FIRST_CHAPTER = '#who-can';

test.describe('the Help page contents', () => {
    test('stay on screen beside the chapters on a wide screen, marking the one being read', async ({ page }, info) => {
        test.skip(info.project.name !== 'desktop', 'The contents column exists from 1024px.');

        await page.goto('/help', { waitUntil: 'networkidle' });
        const contents = page.locator(CONTENTS);
        await expect(contents).toBeInViewport();

        await page.locator(LAST_CHAPTER).scrollIntoViewIfNeeded();
        await expect(page.locator(LAST_CHAPTER)).toBeInViewport();
        await expect(page.locator(FIRST_CHAPTER)).not.toBeInViewport();
        // The point of the change: far down the page, the contents have not scrolled away.
        await expect(contents).toBeInViewport();
        await expect(contents.getByRole('link', { name: 'Why Publish can say no' })).toHaveAttribute('aria-current', 'location');
        // The column is taller than the screen and scrolls on its own; the marked chapter is kept in its view.
        await expect(contents.getByRole('link', { name: 'Why Publish can say no' })).toBeInViewport();

        // And they still work from there: one click back to the first chapter.
        await contents.getByRole('link', { name: 'Before you start: who can do what' }).click();
        await expect(page.locator(FIRST_CHAPTER)).toBeInViewport();
        await expect(contents.getByRole('link', { name: 'Before you start: who can do what' })).toHaveAttribute('aria-current', 'location');

        // Neither narrow-screen way back is shown beside a column that is always there.
        await expect(page.getByRole('link', { name: 'Contents', exact: true })).toBeHidden();
    });

    test('are one tap away on a narrow screen', async ({ page }, info) => {
        test.skip(info.project.name === 'desktop', 'Below 1024px the contents sit above the chapters.');

        await page.goto('/help', { waitUntil: 'networkidle' });
        const contents = page.locator(CONTENTS);

        await page.locator(LAST_CHAPTER).scrollIntoViewIfNeeded();
        await expect(contents).not.toBeInViewport();

        const button = page.getByRole('link', { name: 'Contents', exact: true });
        await expect(button).toBeVisible();
        await button.click();
        await expect(contents.getByRole('heading', { name: 'Contents' })).toBeInViewport();

        // And every chapter ends with the same way back.
        await page.locator(LAST_CHAPTER).scrollIntoViewIfNeeded();
        await page.locator(LAST_CHAPTER).getByRole('link', { name: 'Back to contents' }).click();
        await expect(contents.getByRole('heading', { name: 'Contents' })).toBeInViewport();
    });
});
