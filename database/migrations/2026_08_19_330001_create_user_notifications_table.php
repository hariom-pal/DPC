<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications — M11.
 *
 * **Additive only.** No existing table is altered, so no frozen milestone is
 * touched. The existing SMS and e-mail delivery is untouched too: this is a
 * *third* channel written by a *second* listener on the same event (AD-20's
 * pattern — behaviour is added to a completed milestone by decoration, never by
 * edit), not a replacement for either.
 *
 * **Why an in-app channel is worth a table.** Every workflow notification in this
 * system goes out by SMS and e-mail. The SMS gateway is not procured (**TD-6**)
 * and `FEATURE_NOTIFICATIONS` defaults to off, so in a demo — and on the day the
 * system goes live before the gateway does — *nobody is actually notified of
 * anything*. An in-app record needs no gateway, survives a failed delivery, and
 * is the only channel that can show an actor what is waiting for them when they
 * log in.
 *
 * Not Laravel's own `notifications` table: that stores a serialised payload blob
 * addressed by class name, which is the wrong shape for something a screen has to
 * filter, count and link. These columns are queryable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What it is about. Nullable morph columns rather than a hard FK: a
            // notification outlives the thing it points at, and losing the
            // notification because a draft was deleted would be the wrong trade.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('workflow', 60)->nullable();
            $table->string('event', 60);

            $table->string('title');
            $table->text('body')->nullable();

            // Where to act on it. Stored rather than derived, so a notification
            // written today still links correctly after a route is renamed.
            $table->string('url', 512)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('occurred_at');

            $table->timestamps();

            // The two queries the screen makes: this user's feed, newest first,
            // and this user's unread count.
            $table->index(['user_id', 'occurred_at']);
            $table->index(['user_id', 'read_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
