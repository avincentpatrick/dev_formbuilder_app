<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Enums\QueueName;
use App\Jobs\TenantAwareJob;
use App\Models\Attachment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Queue\DatabaseWorkerPipelineTest;

/**
 * A TenantAwareJob that RECORDS the context it observed, so a test can assert on state that exists
 * only for the duration of handle() on a worker.
 *
 * This is the observation mechanism the ADR-0007 §D4/§D2 tests need: `queue:work` runs the job and
 * returns, so by the time the test resumes, every interesting value (the GUC, the statics, the
 * transaction depth) is already gone. Writing them into a public static during handle() is the only
 * way to see them — a log spy would not capture the GUC, and asserting inside handle() is useless
 * because Worker::process() SWALLOWS exceptions (a failed assertion would just mark the job failed).
 *
 * @see DatabaseWorkerPipelineTest
 */
#[Queue(QueueName::Submissions)]
final class ProbeTenantJob extends TenantAwareJob
{
    /**
     * One entry per execution, in order.
     *
     * @var list<array{label: string, static: ?string, guc: ?string, team: int|string|null, txLevel: int, visible: int}>
     */
    public static array $observations = [];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $label = 'probe',
    ) {}

    public static function reset(): void
    {
        self::$observations = [];
    }

    /**
     * @return array{label: string, static: ?string, guc: ?string, team: int|string|null, txLevel: int, visible: int}|null
     */
    public static function last(): ?array
    {
        return self::$observations === [] ? null : self::$observations[array_key_last(self::$observations)];
    }

    protected function handleForTenant(): void
    {
        /** @var object{v: ?string} $guc */
        $guc = DB::selectOne("select current_setting('app.current_tenant_id', true) as v");

        self::$observations[] = [
            'label' => $this->label,
            // The PHP-side mirror BelongsToTenant reads.
            'static' => TenantContext::currentTenantId(),
            // The database-side GUC RLS actually enforces on. These two agreeing is the whole point.
            'guc' => $guc->v === '' ? null : $guc->v,
            'team' => app(PermissionRegistrar::class)->getPermissionsTeamId(),
            // Proves handleForTenant() really runs inside DB::transaction (§D2), without which
            // applyLocal's transaction-scoped GUC would not survive to the query above.
            'txLevel' => DB::transactionLevel(),
            // A real tenant-scoped read through the RLS policy + BelongsToTenant global scope. If
            // context were wrong or absent this is 0, which is how RLS fails closed.
            'visible' => Attachment::query()->count(),
        ];
    }
}
