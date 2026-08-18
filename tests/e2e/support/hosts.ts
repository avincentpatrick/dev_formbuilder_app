/**
 * The two origins every spec needs (Increment I10e).
 *
 * Extracted because this was about to become the THIRD hand-rolled copy of the same expression —
 * `auth-axe.spec.ts` derives the central host for `/register`, `responsive-axe.spec.ts` derives it for the
 * platform landing page, and the admin-console scan needs it for every route it visits. Three copies of a
 * string transform is how one of them comes to be subtly different.
 */

/** The tenant workspace host — `playwright.config.ts`'s `baseURL`. */
export const tenantOrigin = process.env.E2E_BASE_URL ?? 'http://acme.meridian.test:8000';

/**
 * The central/platform host, derived by dropping the tenant subdomain.
 *
 * A transform rather than a second env var so the two can never disagree about the port, which is what
 * would break first in CI (`ci.yml` maps both `acme.meridian.test` and `meridian.test` to loopback).
 */
export const centralOrigin = tenantOrigin.replace('acme.', '');
