// Increment G8b — happy-dom has no IndexedDB; install fake-indexeddb globally so the Dexie offline-engine
// suites (db / outbox / media-queue / replay / sync-outbox / autosave) run. Each suite uses a unique db name
// (or deletes its db) so cases stay isolated across the shared in-memory backing store.
import 'fake-indexeddb/auto';

// Increment M170 — the builder keeps each unconfirmed edit in `sessionStorage` (`resources/js/components/builder/edit-journal.ts`)
// and puts it back when a store is created. happy-dom keeps one `sessionStorage` per test FILE, and the builder suites build
// every store from the same fixture draft, so one case's kept edit would be put back into the next case's store. Cleared after
// every case, everywhere: no suite relies on `sessionStorage` surviving from one case to the next.
import { afterEach } from 'vitest';

afterEach(() => {
    sessionStorage.clear();
});
