<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * The real {@see DnsTxtResolver}, backed by PHP's system resolver (H22a / ADR-0012).
 *
 * `dns_get_record()` emits a warning and returns `false` on SERVFAIL/timeout rather than throwing, so the
 * `@` is not laziness — it is how the "could not ask" case is converted into the contract's `null`. A
 * definitive "nothing published" comes back as an empty array, and the two must not be confused: only the
 * second is evidence about the tenant.
 *
 * ⚠️ TWO OPERATIONAL FACTS THAT BELONG WITH THE CODE.
 *
 * 1. `dns_get_record()` TAKES NO TIMEOUT. A dead nameserver costs roughly (attempts × servers × 5s) on
 *    the OS resolver's own budget, with no way to shorten it from PHP. That is why the sweep bounds its
 *    batch instead of trusting the job timeout — see VerifyCustomDomainsJob.
 * 2. DNS_TXT is one of the record types with the weakest historical support in Windows PHP builds, and
 *    Track B targets a Windows host. This interface exists partly so swapping in a `dig`-shelling or
 *    resolver-library implementation is a one-line binding change in AppServiceProvider; verify on the
 *    real box before H22 is enabled in production.
 */
final class SystemDnsTxtResolver implements DnsTxtResolver
{
    /**
     * @return list<string>|null
     */
    public function txt(string $name): ?array
    {
        /** @var list<array<string, mixed>>|false $records */
        $records = @dns_get_record($name, DNS_TXT);

        if ($records === false) {
            return null; // the lookup failed — says nothing about the tenant
        }

        $values = [];

        foreach ($records as $record) {
            // A TXT record longer than 255 bytes arrives split into `entries`; `txt` is the joined form.
            // Prefer the joined value so a long token is never truncated at a chunk boundary.
            $value = $record['txt'] ?? null;

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
