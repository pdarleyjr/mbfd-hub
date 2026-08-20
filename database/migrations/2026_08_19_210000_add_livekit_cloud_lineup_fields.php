<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_conference_sessions', function (Blueprint $table): void {
            $table->string('livekit_profile', 24)->default('self_hosted')->after('active_key');
        });
        Schema::table('video_conference_participations', function (Blueprint $table): void {
            $table->foreignId('employee_id')->nullable()->change();
            $table->string('launch_context_hash', 64)->nullable()->after('display_name');
            $table->unsignedBigInteger('downstream_bytes')->default(0);
            $table->unsignedBigInteger('packets_received')->default(0);
            $table->unsignedBigInteger('packets_lost')->default(0);
            $table->unsignedInteger('jitter_ms')->default(0);
            $table->timestampTz('stats_sampled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_conference_participations', function (Blueprint $table): void {
            $table->dropColumn([
                'launch_context_hash', 'downstream_bytes', 'packets_received',
                'packets_lost', 'jitter_ms', 'stats_sampled_at',
            ]);
            $table->foreignId('employee_id')->nullable(false)->change();
        });
        Schema::table('video_conference_sessions', function (Blueprint $table): void {
            $table->dropColumn('livekit_profile');
        });
    }
};
