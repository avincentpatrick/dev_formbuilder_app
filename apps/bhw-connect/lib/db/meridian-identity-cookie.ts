/**
 * The name of Meridian's session cookie (Laravel's `config/session.php`: the slug of its app name plus
 * `-session`). On its own so `proxy.ts` can test for the cookie without importing the server-only
 * module that asks Meridian about it (`lib/db/meridian-identity.ts`).
 */
export const MERIDIAN_SESSION_COOKIE = "meridian-session";
