import { ref, type Ref } from 'vue';

/**
 * A tiny aria-live message channel (UX §10.1). The SPA renders ONE visually-hidden polite region (in
 * `RuntimeShell`) bound to `message`; navigation, validation, and show/hide events call `announce()`.
 * A repeated identical string is nudged with a trailing zero-width space so the screen reader still speaks it.
 */
export interface Announcer {
    message: Ref<string>;
    announce: (text: string) => void;
}

const ZWSP = String.fromCharCode(0x200b);

export function createAnnouncer(): Announcer {
    const message = ref('');

    function announce(text: string): void {
        message.value = message.value === text ? `${text}${ZWSP}` : text;
    }

    return { message, announce };
}
