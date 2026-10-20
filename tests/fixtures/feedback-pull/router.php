<?php

declare(strict_types=1);

/*
 * A stand-in for the platform support API (M175, `R-78f43f26`), served by `php -S` for
 * tests/Feature/Docs/FeedbackPullScriptTest.php — so the pull script is driven end to end over real HTTP, with
 * no application, no database and no network beyond the loopback.
 *
 * M179 adds what the pull reads and writes since the threads (`D151`) and the relay (ADR-0023 §D7): a lookup by
 * reference (`U1` one report, `DUP` two, anything else none), the reports with new member messages
 * (`commented_since`), the relayed reports, and the two POSTs, which are logged like the PATCHes.
 *
 * It answers the endpoints `scripts/feedback-pull.php` calls, refuses any bearer but the one the test
 * generated (FEEDBACK_PULL_FIXTURE_TOKEN — generated at run time, never committed, so a secret scanner has
 * nothing to find), and appends every PATCH body to FEEDBACK_PULL_FIXTURE_LOG so the test can read what was
 * sent. A report id ending `refuse` answers 422, as the real API does for a step the lifecycle does not offer.
 */

$token = (string) getenv('FEEDBACK_PULL_FIXTURE_TOKEN');
$log = (string) getenv('FEEDBACK_PULL_FIXTURE_LOG');

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = (string) $_SERVER['REQUEST_METHOD'];

$json = static function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_THROW_ON_ERROR);
};

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer '.$token) {
    $json(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Authentication is required.', 'details' => null]]);

    return true;
}

$row = static fn (string $id, string $type, string $status, bool $shot): array => [
    'id' => $id,
    'tenant_id' => '0192e2e0-0000-7000-8000-0000000000aa',
    'tenant_name' => 'Staging',
    'type' => $type,
    'type_label' => ucfirst($type),
    'status' => $status,
    'status_label' => $status === 'reviewed' ? 'Ongoing' : 'New',
    'route' => '/forms/1/builder',
    'page_url' => 'https://staging.example.test/forms/1/builder',
    'page_title' => 'Builder — Household survey',
    'remarks' => $type === 'bug' ? 'Dragging a question does nothing.' : 'Could the inbox remember my filter?',
    'browser_info' => ['component' => 'forms/Builder', 'bundle' => 'abc1234'],
    'reporter' => ['name' => 'Tess Tester', 'email' => 'tess@staging.example.test'],
    'reporter_role' => 'form_editor',
    'reporter_role_label' => 'Form Editor',
    'submitted_at' => '2026-10-16T09:30:00+00:00',
    'resolved_at' => null,
    'resolution_note' => null,
    'has_screenshot' => $shot,
];

$threadRow = [
    ...$row('0192e2e0-0000-7000-8000-000000000003', 'suggestion', 'reviewed', false),
    'source' => 'relayed',
    'reference' => 'U8',
    'title' => 'Calculations by clicking',
    'comments' => [
        ['id' => 'c1', 'author_kind' => 'member', 'author' => ['name' => 'Tess Tester', 'email' => 'tess@staging.example.test'], 'action' => 'reopened', 'body' => 'Still cut off on the tablet.', 'created_at' => '2026-10-20T01:00:00+00:00'],
        ['id' => 'c2', 'author_kind' => 'support', 'author' => null, 'action' => null, 'body' => 'Which tablet, please?', 'created_at' => '2026-10-20T02:00:00+00:00'],
        ['id' => 'c3', 'author_kind' => 'member', 'author' => ['name' => 'Ana Analyst', 'email' => 'ana@staging.example.test'], 'action' => null, 'body' => 'Same on my phone.', 'created_at' => '2026-10-20T03:00:00+00:00'],
    ],
];

if ($method === 'GET' && $path === '/api/platform/v1/feedback') {
    parse_str((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);

    if (isset($query['reference'])) {
        $found = match ($query['reference']) {
            'U1' => [[...$row('0192e2e0-0000-7000-8000-000000000011', 'bug', 'reviewed', false), 'reference' => 'U1']],
            'DUP' => [[...$row('0192e2e0-0000-7000-8000-000000000021', 'bug', 'reviewed', false), 'reference' => 'DUP'], [...$row('0192e2e0-0000-7000-8000-000000000022', 'bug', 'reviewed', false), 'reference' => 'DUP']],
            default => [],
        };
        $json(200, ['data' => $found, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($found), 'per_page' => 2]]);

        return true;
    }

    if (isset($query['commented_since'])) {
        $json(200, ['data' => [$threadRow], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 100]]);

        return true;
    }

    if (($query['source'] ?? '') === 'relayed') {
        $relayed = [
            [...$row('0192e2e0-0000-7000-8000-000000000031', 'bug', 'resolved', false), 'status_label' => 'Resolved'],
            [...$row('0192e2e0-0000-7000-8000-000000000032', 'bug', 'resolved', false), 'status_label' => 'Resolved'],
            $threadRow,
            [...$row('0192e2e0-0000-7000-8000-000000000034', 'bug', 'confirmed', false), 'status_label' => 'Confirmed working'],
        ];
        $json(200, ['data' => $relayed, 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 4, 'per_page' => 100]]);

        return true;
    }

    if (($query['status'] ?? '') === 'new' && ($query['per_page'] ?? '') === '1') {
        $json(200, ['data' => [$row('0192e2e0-0000-7000-8000-000000000001', 'bug', 'new', true)],
            'meta' => ['current_page' => 1, 'last_page' => 3, 'total' => 3, 'per_page' => 1]]);

        return true;
    }

    $json(200, [
        'data' => [
            $row('0192e2e0-0000-7000-8000-000000000001', 'bug', 'new', true),
            $row('0192e2e0-0000-7000-8000-000000000002', 'suggestion', 'reviewed', false),
        ],
        'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 2, 'per_page' => 100],
    ]);

    return true;
}

if ($method === 'GET' && preg_match('#^/api/platform/v1/feedback/([0-9a-z-]+)/screenshot$#', $path) === 1) {
    header('Content-Type: image/png');
    echo 'FIXTURE-PNG-BYTES';

    return true;
}

if ($method === 'POST' && $path === '/api/platform/v1/feedback/relayed') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    file_put_contents($log, json_encode(['method' => 'POST', 'path' => $path, 'body' => $body])."\n", FILE_APPEND);
    $json(200, ['data' => array_map(
        static fn (array $item): array => ['reference' => $item['reference'], 'id' => '0192e2e0-0000-7000-8000-0000000000ff', 'outcome' => 'created'],
        (array) ($body['items'] ?? []),
    )]);

    return true;
}

if ($method === 'POST' && preg_match('#^/api/platform/v1/feedback/([0-9a-z-]+)/comments$#', $path, $m) === 1) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    file_put_contents($log, json_encode(['method' => 'POST', 'path' => $path, 'body' => $body])."\n", FILE_APPEND);
    $json(200, ['data' => ['id' => 'c9', 'feedback_report_id' => $m[1], 'author_kind' => 'support', 'body' => $body['body'] ?? null]]);

    return true;
}

if ($method === 'PATCH' && preg_match('#^/api/platform/v1/feedback/([0-9a-z-]+)$#', $path, $m) === 1) {
    $body = (string) file_get_contents('php://input');
    file_put_contents($log, json_encode(['id' => $m[1], 'body' => json_decode($body, true)])."\n", FILE_APPEND);

    if (str_ends_with($m[1], 'refuse')) {
        $json(422, ['error' => ['code' => 'invalid_transition', 'message' => 'A feedback report cannot go from Resolved to Declined.', 'details' => null]]);

        return true;
    }

    $decoded = json_decode($body, true);
    $json(200, ['data' => ['id' => $m[1], 'status' => $decoded['status'] ?? null, 'resolution_note' => $decoded['note'] ?? null]]);

    return true;
}

$json(404, ['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.', 'details' => null]]);

return true;
