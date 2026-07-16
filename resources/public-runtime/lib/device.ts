/**
 * A stable per-device identifier (Increment G8b) sent as `device_id` on every submission, so offline replays
 * and conflict review can attribute records to the physical device that collected them (data-dictionary §7).
 * Minted once with a UUIDv7 and persisted in localStorage — device-scoped, synchronous (so it is available in
 * the hot submit path), and survives reloads. Fits the 100-char `device_id` column comfortably.
 */

import { uuidv7 } from './uuid';

export const DEVICE_ID_KEY = 'meridian:device-id';

/** Read the device id, minting + persisting one on first use. Best-effort: falls back to a fresh id if storage is denied. */
export function getDeviceId(storage: Storage = window.localStorage): string {
    try {
        const existing = storage.getItem(DEVICE_ID_KEY);
        if (existing !== null && existing !== '') {
            return existing;
        }
        const minted = uuidv7();
        storage.setItem(DEVICE_ID_KEY, minted);
        return minted;
    } catch {
        // Private mode / storage denied — a per-session id is still better than none.
        return uuidv7();
    }
}
