<script setup lang="ts">
/**
 * Ambient "you are offline" pill (Increment G8a). Shown while the device reports offline: the form still
 * renders and autosaves from cache, but submitting is deferred until reconnect.
 *
 * Rebuilt on `MdsBadge` in I10d (Standing Rule 2 — one shared design system, no exceptions). It deletes all
 * of this component's scoped CSS and costs no new bytes, because `MdsBadge` is already pulled into the bundle
 * by the sync surface beside it. It is NOT the last such conversion: several other guest-runtime components
 * still style raw elements from `--mds-*` tokens, and converting them is its own increment — this one is
 * done here because the sync surface it sits beside was being rebuilt anyway.
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
