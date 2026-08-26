<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Display\DisplayReadiness;
use Tests\TestCase;

class DisplayReadinessTest extends TestCase
{
    public function test_unknown_daily_checkout_classification_blocks_ready_status(): void
    {
        $readiness = DisplayReadiness::compute(
            requiredApparatusCount: 1,
            checkedApparatusCount: 1,
            attentionApparatusCount: 0,
            reviewPendingApparatusCount: 0,
            notCheckedApparatusCount: 0,
            unknownApparatusCount: 1,
            inServiceCount: 2,
            outOfServiceCount: 0,
            maintenanceCount: 0,
            openDefects: 0,
            criticalDefects: 0,
            lastStationInspectionStatus: 'pass',
            stationInspectionAgeDays: 0,
            pendingEquipmentRequests: 0,
            criticalPendingEquipmentRequests: 0,
            snapshotAgeSeconds: 0,
        );

        $this->assertSame(DisplayReadiness::STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('1 apparatus needs daily checkout policy classification', $readiness['reasons']);
    }

    public function test_review_pending_or_attention_apparatus_prevents_ready_status(): void
    {
        $readiness = DisplayReadiness::compute(
            requiredApparatusCount: 2,
            checkedApparatusCount: 2,
            attentionApparatusCount: 1,
            reviewPendingApparatusCount: 1,
            notCheckedApparatusCount: 0,
            unknownApparatusCount: 0,
            inServiceCount: 2,
            outOfServiceCount: 0,
            maintenanceCount: 0,
            openDefects: 0,
            criticalDefects: 0,
            lastStationInspectionStatus: 'pass',
            stationInspectionAgeDays: 0,
            pendingEquipmentRequests: 0,
            criticalPendingEquipmentRequests: 0,
            snapshotAgeSeconds: 0,
        );

        $this->assertSame(DisplayReadiness::STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('1 apparatus checkout requires review', $readiness['reasons']);
        $this->assertContains('1 checked apparatus has an unresolved critical defect', $readiness['reasons']);
    }

    public function test_not_checked_required_apparatus_prevents_ready_status(): void
    {
        $readiness = DisplayReadiness::compute(
            requiredApparatusCount: 10,
            checkedApparatusCount: 9,
            attentionApparatusCount: 0,
            reviewPendingApparatusCount: 0,
            notCheckedApparatusCount: 1,
            unknownApparatusCount: 0,
            inServiceCount: 10,
            outOfServiceCount: 0,
            maintenanceCount: 0,
            openDefects: 0,
            criticalDefects: 0,
            lastStationInspectionStatus: 'pass',
            stationInspectionAgeDays: 0,
            pendingEquipmentRequests: 0,
            criticalPendingEquipmentRequests: 0,
            snapshotAgeSeconds: 0,
        );

        $this->assertSame(DisplayReadiness::STATUS_ATTENTION, $readiness['status']);
        $this->assertContains('1 required apparatus is not checked today', $readiness['reasons']);
    }
}
