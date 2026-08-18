<?php

declare(strict_types=1);

use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

uses(RefreshDatabase::class);

/**
 * `notifications` is ALSO the table Laravel's own database notification channel expects, and `App\Models\User`
 * uses `Notifiable`. Nothing in this application uses that channel, and this file is what keeps it that way.
 *
 * Why it needs a test rather than a comment: before I3 the framework accessors failed with "relation
 * notifications does not exist", which nobody could misread. Now they would fail with `column
 * notifications.notifiable_type does not exist` — a message that reads like a bug in this increment, on a
 * code path that only runs when someone reaches for `$user->unreadNotifications` at 3am.
 */
it('keeps the table in OUR shape, not the framework channel\'s', function (): void {
    expect(Schema::hasColumn('notifications', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'notifiable_type'))->toBeFalse()
        ->and(Schema::hasColumn('notifications', 'notifiable_id'))->toBeFalse();

    // A framework migration slipping into the tree (or someone "fixing" the collision by adopting
    // DatabaseNotification) reddens here rather than in a stack trace.
    expect((new Notification)->getTable())->toBe('notifications')
        ->and((new DatabaseNotification)->getTable())->toBe('notifications')
        ->and(Notification::class)->not->toBe(DatabaseNotification::class);
});

it('never routes a notification through the database channel', function (): void {
    $classes = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Notifications'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace([app_path(), '.php', DIRECTORY_SEPARATOR], ['', '', '\\'], $file->getPathname());
        $class = 'App'.$relative;

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[] = $class;
    }

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

        // CODE only — comments are stripped first, because the rule itself is written down in a docblock
        // that names the forbidden string, and a raw substring search would flag the documentation of the
        // very thing it is enforcing.
        $strings = array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING,
        );

        // A `'database'` channel would silently target OUR table with the framework's column expectations.
        // The in-app half is App\Models\Notification, written by NotificationDispatcher — never a channel.
        foreach ($strings as $token) {
            expect(trim((string) $token[1], "'\""))->not->toBe('database', "{$class} names the database notification channel");
        }
    }
});
