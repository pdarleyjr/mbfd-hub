<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Fleet\InspectionReviewers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a message spoke as, snapshotted when it is written: `logistics` (the author could review
 * at that moment) or `member`. Read-time role checks relabel history when someone's
 * capabilities change; this column does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_issue_messages', function (Blueprint $table): void {
            $table->string('author_context', 16)->default('member');
        });

        // Backfill: the best available evidence for existing rows is the author's current standing.
        DB::table('apparatus_issue_messages')
            ->whereNotNull('author_user_id')
            ->distinct()
            ->orderBy('author_user_id')
            ->pluck('author_user_id')
            ->chunk(200)
            ->each(function ($authorIds): void {
                $reviewerIds = User::query()
                    ->whereIn('id', $authorIds->all())
                    ->get()
                    ->filter(static fn (User $author): bool => InspectionReviewers::canReview($author))
                    ->pluck('id')
                    ->all();
                if ($reviewerIds !== []) {
                    DB::table('apparatus_issue_messages')
                        ->whereIn('author_user_id', $reviewerIds)
                        ->update(['author_context' => 'logistics']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('apparatus_issue_messages', function (Blueprint $table): void {
            $table->dropColumn('author_context');
        });
    }
};
