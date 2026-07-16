// Increment G8b — happy-dom has no IndexedDB; install fake-indexeddb globally so the Dexie offline-engine
// suites (db / outbox / media-queue / replay / sync-outbox / autosave) run. Each suite uses a unique db name
// (or deletes its db) so cases stay isolated across the shared in-memory backing store.
import 'fake-indexeddb/auto';
