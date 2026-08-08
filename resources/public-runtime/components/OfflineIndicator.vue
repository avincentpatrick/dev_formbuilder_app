<script setup lang="ts">
/**
 * Ambient "you are offline" pill (Increment G8a). Shown while the device reports offline: the form still
 * renders and autosaves from cache, but submitting is deferred until reconnect.
 *
 * Rebuilt on `MdsBadge` in I10d (Standing Rule 2 — one shared design system, no exceptions). It had been one
 * of only two components in the guest runtime hand-rolling its own chrome from raw elements and `--mds-*`
 * tokens; the conversion deletes all of its scoped CSS and costs no new bytes, because `MdsBadge` was already
 * pulled into this bundle by the sync surface beside it.
 *
 * `role="status"` stays on the wrapper rather than moving inside: it announces the state change once, and
 * `MdsBadge` is a presentational element with no role of its own to conflict with.
 */
import { MdsBadge } from '@meridian/design-system';
</script>

<template>
    <span role="status">
        <MdsBadge variant="neutral" label="Offline — your answers are saved on this device" />
    </span>
</template>
