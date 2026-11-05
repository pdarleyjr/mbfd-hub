<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support Services equipment priorities (docs/support-services-triage.md). Additive only.
 *
 *  - support_equipment_rules: the admin-managed catalog that says what is LOW, MEDIUM or HIGH and how a
 *    member should be guided. Every edit is versioned in support_equipment_rule_revisions.
 *  - support_triage_levels: one row per open record (defect, station request, supply request, message thread)
 *    holding the level the catalog produced, an optional per-record staff override, and the policy version.
 *    Dashboards, notifications and the reply engine all read this one projection.
 *
 * The record's own severity, operational impact and action status are untouched and stay authoritative for
 * what they mean; a defect flagged significant or out of service always reads HIGH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_equipment_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            // Lower-cased, punctuation-free form of name, for exact matching and the unique key.
            $table->string('normalized_name', 120);
            $table->json('aliases')->nullable();
            $table->string('category', 32);
            $table->string('level', 8); // low | medium | high
            $table->string('handler', 24)->default('support_services');
            // Optional checklist scope (engine, engine2, ladder1, ladder3, rescue, fireboat6, default); null = everywhere.
            $table->string('apparatus_scope', 32)->nullable();
            // Optional custom member reply for this item; null = the category/level default message.
            $table->text('member_response')->nullable();
            $table->boolean('is_ppe')->default(false);
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_expensive')->default(false);
            $table->string('contact_300', 16)->default('urgent'); // never | urgent | always
            $table->boolean('active')->default(true);
            $table->string('source', 16)->default('admin'); // seeded | admin
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['normalized_name', 'apparatus_scope']);
            $table->index(['level', 'active']);
        });

        Schema::create('support_equipment_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('support_equipment_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('edited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('edited_by_name', 160);
            $table->timestampTz('created_at');
            $table->unique(['rule_id', 'version']);
        });

        Schema::create('support_triage_levels', function (Blueprint $table): void {
            $table->id();
            // apparatus_defect | station_request | station_supply_request | employee_logistics_thread
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('auto_level', 8);
            $table->string('override_level', 8)->nullable();
            $table->string('level', 8); // effective: override_level ?? auto_level (record flags may still raise it)
            $table->string('category', 32)->nullable();
            $table->string('handler', 24)->nullable();
            $table->foreignId('rule_id')->nullable()->constrained('support_equipment_rules')->nullOnDelete();
            $table->boolean('unclassified')->default(false);
            $table->json('reasons')->nullable();
            $table->unsignedInteger('policy_version');
            $table->foreignId('override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('override_at')->nullable();
            $table->timestampTz('computed_at');
            $table->timestampsTz();
            $table->unique(['subject_type', 'subject_id']);
            $table->index(['subject_type', 'level']);
        });

        Schema::table('ai_automation_settings', function (Blueprint $table): void {
            // Bumped on every equipment-rule change; records computed under an older version can be re-checked.
            $table->unsignedInteger('triage_policy_version')->default(1);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Support triage tables are only dropped in local or testing.');
        }

        Schema::table('ai_automation_settings', function (Blueprint $table): void {
            $table->dropColumn('triage_policy_version');
        });
        Schema::dropIfExists('support_triage_levels');
        Schema::dropIfExists('support_equipment_rule_revisions');
        Schema::dropIfExists('support_equipment_rules');
    }
};
