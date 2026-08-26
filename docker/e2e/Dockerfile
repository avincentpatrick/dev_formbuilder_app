# Linux Playwright runner — the environment `README.md` has always claimed the e2e suite runs in.
#
# ⚠️ WHY THIS EXISTS AT ALL. Until M19 that claim was false: the suite ran against the Windows-installed
# browsers, and the two do not agree. Four real defects were quarantined in `tests/e2e/support/axe.ts`
# as "does not reproduce on a Windows host" when what could not reproduce was the FONT STACK, not the
# defect. `tokens/typography.json` resolves `system-ui` to Segoe UI Variable Display on Windows and to
# DejaVu Sans on a CI runner, and DejaVu is ~27% wider for the same string.
#
# ⛔ THE BASE IMAGE ALONE IS NOT ENOUGH, AND THAT IS THE WHOLE REASON FOR THE ONE `RUN` BELOW.
# `mcr.microsoft.com/playwright` ships exactly the `--with-deps` font set and NO DejaVu, so fontconfig
# resolves `system-ui` to WenQuanYi Zen Hei — a CJK face, narrower for Latin than either real answer.
# Measured: the submissions `<h1>` renders 256px there, 324px with DejaVu present, and CI reported the
# overrun that only the second number produces. `fonts-dejavu-core` is what a GitHub `ubuntu-latest`
# runner has and this image does not; adding it is what makes local and CI agree to the pixel.
#
# Pinned to the Playwright version in `package-lock.json`. Bump both together, or the image's bundled
# browsers and `node_modules/playwright-core`'s expected revision drift apart.
FROM mcr.microsoft.com/playwright:v1.61.1-noble

RUN apt-get update \
 && apt-get install -y --no-install-recommends fonts-dejavu-core \
 && rm -rf /var/lib/apt/lists/* \
 && fc-cache -f
