<?php
/**
 * Patch all Filament resource and relation manager files to add
 * pxlrbt/filament-excel export actions (header + bulk).
 *
 * Run: php patch_export_actions.php
 */

$baseDir = '/var/www/html/app/Filament';

// Files to patch: [path => filename_prefix]
$files = [
    // === LOGISTICS RESOURCES ===
    'Resources/ApparatusResource.php'                                                  => 'mbfd_apparatuses',
    'Resources/CapitalProjectResource.php'                                             => 'mbfd_capital_projects',
    'Resources/DefectResource.php'                                                     => 'mbfd_defects',
    'Resources/EquipmentItemResource.php'                                              => 'mbfd_equipment_items',
    'Resources/InspectionResource.php'                                                 => 'mbfd_inspections',
    'Resources/InventoryItemResource.php'                                              => 'mbfd_inventory_items',
    'Resources/InventoryLocationResource.php'                                          => 'mbfd_inventory_locations',
    'Resources/RecommendationResource.php'                                             => 'mbfd_recommendations',
    'Resources/ShopWorkResource.php'                                                   => 'mbfd_shop_works',
    'Resources/StationResource.php'                                                    => 'mbfd_stations',
    'Resources/TodoResource.php'                                                       => 'mbfd_todos',
    'Resources/Under25kProjectResource.php'                                            => 'mbfd_under25k_projects',
    'Resources/UniformResource.php'                                                    => 'mbfd_uniforms',
    'Resources/UnitMasterVehicleResource.php'                                          => 'mbfd_unit_master_vehicles',
    'Resources/UserResource.php'                                                       => 'mbfd_users',

    // === LOGISTICS RELATION MANAGERS ===
    'Resources/ApparatusResource/RelationManagers/DefectsRelationManager.php'         => 'mbfd_apparatus_defects',
    'Resources/ApparatusResource/RelationManagers/InspectionsRelationManager.php'     => 'mbfd_apparatus_inspections',
    'Resources/CapitalProjectResource/RelationManagers/MilestonesRelationManager.php' => 'mbfd_milestones',
    'Resources/CapitalProjectResource/RelationManagers/UpdatesRelationManager.php'    => 'mbfd_cp_updates',
    'Resources/StationResource/RelationManagers/ApparatusesRelationManager.php'       => 'mbfd_station_apparatuses',
    'Resources/StationResource/RelationManagers/CapitalProjectsRelationManager.php'   => 'mbfd_station_cp',
    'Resources/StationResource/RelationManagers/InventorySubmissionsRelationManager.php' => 'mbfd_inventory_submissions',
    'Resources/StationResource/RelationManagers/RoomsRelationManager.php'             => 'mbfd_station_rooms',
    'Resources/StationResource/RelationManagers/StationInventoryItemsRelationManager.php' => 'mbfd_station_inventory',
    'Resources/StationResource/RelationManagers/StationSupplyRequestsRelationManager.php' => 'mbfd_supply_requests',
    'Resources/StationResource/RelationManagers/Under25kProjectsRelationManager.php'  => 'mbfd_station_under25k',
    'Resources/Under25kProjectResource/RelationManagers/UpdatesRelationManager.php'   => 'mbfd_under25k_updates',

    // === TRAINING RESOURCES ===
    'Training/Resources/ExternalNavItemResource.php'                                   => 'mbfd_training_nav_items',
    'Training/Resources/ExternalSourceResource.php'                                    => 'mbfd_training_sources',
    'Training/Resources/TrainingTodoResource.php'                                      => 'mbfd_training_todos',

    // === WORKGROUP RESOURCES ===
    'Resources/Workgroup/CandidateProductResource.php'                                 => 'mbfd_wg_candidate_products',
    'Resources/Workgroup/EvaluationCategoryResource.php'                               => 'mbfd_wg_eval_categories',
    'Resources/Workgroup/EvaluationCriterionResource.php'                              => 'mbfd_wg_eval_criteria',
    'Resources/Workgroup/EvaluationSubmissionResource.php'                             => 'mbfd_wg_eval_submissions',
    'Resources/Workgroup/EvaluationTemplateResource.php'                               => 'mbfd_wg_eval_templates',
    'Resources/Workgroup/WorkgroupFileResource.php'                                    => 'mbfd_wg_files',
    'Resources/Workgroup/WorkgroupMemberResource.php'                                  => 'mbfd_wg_members',
    'Resources/Workgroup/WorkgroupResource.php'                                        => 'mbfd_workgroups',
    'Resources/Workgroup/WorkgroupSessionResource.php'                                 => 'mbfd_wg_sessions',

    // === WORKGROUP RELATION MANAGERS ===
    'Resources/Workgroup/RelationManagers/CriteriaRelationManager.php'                => 'mbfd_wg_criteria',
    'Resources/Workgroup/RelationManagers/FilesRelationManager.php'                    => 'mbfd_wg_rm_files',
    'Resources/Workgroup/RelationManagers/MembersRelationManager.php'                  => 'mbfd_wg_rm_members',
    'Resources/Workgroup/RelationManagers/SessionsRelationManager.php'                 => 'mbfd_wg_rm_sessions',
];

$useImports = "use pxlrbt\\FilamentExcel\\Actions\\Tables\\ExportAction;\nuse pxlrbt\\FilamentExcel\\Actions\\Tables\\ExportBulkAction;\nuse pxlrbt\\FilamentExcel\\Exports\\ExcelExport;";

$patched = 0;
$skipped = 0;
$errors = [];

foreach ($files as $relPath => $prefix) {
    $filePath = $baseDir . '/' . $relPath;

    if (!file_exists($filePath)) {
        echo "[SKIP] File not found: $filePath\n";
        $skipped++;
        continue;
    }

    $content = file_get_contents($filePath);

    // Skip if already patched
    if (strpos($content, 'pxlrbt\\FilamentExcel') !== false || strpos($content, "pxlrbt\FilamentExcel") !== false) {
        echo "[ALREADY PATCHED] $relPath\n";
        $skipped++;
        continue;
    }

    // Determine if file has a table() method
    if (strpos($content, 'public static function table(') === false && strpos($content, 'public function table(') === false) {
        echo "[NO TABLE] $relPath\n";
        $skipped++;
        continue;
    }

    $original = $content;

    // 1. Add use imports after the last existing `use` statement
    if (preg_match('/^use .+;$/m', $content)) {
        // Find the last use statement
        preg_match_all('/^use .+;$/m', $content, $matches, PREG_OFFSET_CAPTURE);
        $lastMatch = end($matches[0]);
        $insertPos = $lastMatch[1] + strlen($lastMatch[0]);
        $content = substr($content, 0, $insertPos) . "\n" . $useImports . substr($content, $insertPos);
    }

    // 2. Add headerActions to table() - insert before ->filters or ->actions or ->bulkActions or ->columns
    // Strategy: find ->columns([ pattern and inject headerActions() before it, OR inject after ->defaultSort or similar
    // Safer: inject as first chain call after "return $table" or after first column block ends
    // We'll inject ->headerActions([...]) before ->actions([ OR ->bulkActions([ OR ->filters([

    $headerActionCode = "\n            ->headerActions([\n                ExportAction::make('export')\n                    ->label('Export')\n                    ->color('gray')\n                    ->icon('heroicon-o-arrow-down-tray')\n                    ->exports([\n                        ExcelExport::make('xlsx')\n                            ->label('Export as Excel (.xlsx)')\n                            ->fromTable()\n                            ->withFilename('{$prefix}_' . date('Y-m-d')),\n                        ExcelExport::make('csv')\n                            ->label('Export as CSV (.csv)')\n                            ->fromTable()\n                            ->withFilename('{$prefix}_' . date('Y-m-d'))\n                            ->withWriterType(\\Maatwebsite\\Excel\\Excel::CSV),\n                    ]),\n            ])";

    $bulkActionCode = "                    ExportBulkAction::make('export_selected')\n                        ->label('Export Selected')\n                        ->exports([\n                            ExcelExport::make('xlsx')\n                                ->label('Export as Excel (.xlsx)')\n                                ->fromTable()\n                                ->withFilename('{$prefix}_selected_' . date('Y-m-d')),\n                            ExcelExport::make('csv')\n                                ->label('Export as CSV (.csv)')\n                                ->fromTable()\n                                ->withFilename('{$prefix}_selected_' . date('Y-m-d'))\n                                ->withWriterType(\\Maatwebsite\\Excel\\Excel::CSV),\n                        ]),\n";

    // Inject headerActions before ->actions([ or ->bulkActions([ or ->filters([
    $injectedHeader = false;
    foreach (['->actions([', '->bulkActions([', '->filters(['] as $anchor) {
        if (strpos($content, $anchor) !== false) {
            $content = preg_replace(
                '/' . preg_quote($anchor, '/') . '/',
                $headerActionCode . "\n            " . ltrim($anchor),
                $content,
                1
            );
            $injectedHeader = true;
            break;
        }
    }

    if (!$injectedHeader) {
        // Inject before closing semicolon of the table return
        $content = preg_replace('/(\s*;)(\s*\}[\s\S]*?public static function getRelations)/', $headerActionCode . "\n            ;\n$2", $content, 1);
    }

    // 3. Inject ExportBulkAction into existing BulkActionGroup::make([
    if (strpos($content, 'BulkActionGroup::make([') !== false) {
        $content = preg_replace(
            '/(BulkActionGroup::make\(\[)(\s*)/',
            '$1$2' . $bulkActionCode,
            $content,
            1
        );
    } elseif (strpos($content, '->bulkActions([') !== false) {
        // No BulkActionGroup, inject directly
        $content = preg_replace(
            '/(->bulkActions\(\[)(\s*)/',
            '$1$2' . $bulkActionCode,
            $content,
            1
        );
    } else {
        // No bulkActions at all — add it
        $fullBulkCode = "\n            ->bulkActions([\n                Tables\\Actions\\BulkActionGroup::make([\n                    " . $bulkActionCode . "                ]),\n            ])";

        // Inject before end of table return statement
        if ($injectedHeader) {
            // Already injected header, add bulk after headerActions block
            $content = str_replace(
                $headerActionCode . "\n            ->actions([",
                $headerActionCode . $fullBulkCode . "\n            ->actions([",
                $content
            );
        }
    }

    if ($content === $original) {
        echo "[NO CHANGE] $relPath\n";
        $skipped++;
        continue;
    }

    file_put_contents($filePath, $content);
    echo "[PATCHED] $relPath\n";
    $patched++;
}

echo "\n=== DONE: $patched patched, $skipped skipped ===\n";
if (!empty($errors)) {
    echo "ERRORS:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
