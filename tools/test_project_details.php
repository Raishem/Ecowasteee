<?php
// Lightweight test script: checks project-detail pages include canonical stages
// and validates presence of client-side material append logic so add_material
// can be appended without a reload.

$base = __DIR__ . '/../';
$files = [];
// discover all project_details variants that exist in the repository root
$candidates = glob(__DIR__ . '/../project_details*.php');
foreach ($candidates as $path) {
    $files[] = basename($path);
}
// ensure the canonical file is always present in the list to validate its client-append logic
if (!in_array('project_details.php', $files, true) && file_exists(__DIR__ . '/../project_details.php')) $files[] = 'project_details.php';

$errors = [];
foreach ($files as $f) {
    $path = $base . $f;
    if (!file_exists($path)) { $errors[] = "$f: missing"; continue; }
    $txt = file_get_contents($path);
    // check inclusion of centralized stages
    if (stripos($txt, "includes/project_stages.php") === false) {
        $errors[] = "$f: canonical include missing";
    }

    // check that the canonical stage names appear (Preparation/Construction/Share)
    foreach (['Preparation','Construction','Share'] as $stage) {
        if (stripos($txt, $stage) === false) {
            $errors[] = "$f: missing canonical stage label '$stage'";
        }
    }
}

// Basic client-side check for add-material append logic in the main page
$main = $base . 'project_details.php';
if (file_exists($main)) {
    $txt = file_get_contents($main);
    // look for an append to materials list or creation of material-item element
    $ok = (stripos($txt, "materialsList.appendChild") !== false) || (stripos($txt, "class=\'material-item\'") !== false) || (stripos($txt, "material-item')") !== false) || (stripos($txt, 'insertMaterialIntoDOM') !== false);
    if (!$ok) {
        $errors[] = "project_details.php: missing client append logic for add_material (materialsList.appendChild or material-item creation)";
    }

    // verify that deletion handler checks for obtained state (prevents upload button showing incorrectly)
    if (stripos($txt, 'isObtained') === false && stripos($txt, 'mat-qty') === false) {
        $errors[] = "project_details.php: delete handler may not check obtained state (expected 'isObtained' or 'mat-qty' checks)";
    }
}

// Try a runtime HTTP fetch for each page (best-effort). If the local dev server is not running
// or the page returns no HTML, fall back to static file checks above. This helps catch
// runtime rendering issues (e.g. missing Preparation tab when materials are absent).
$baseUrl = 'http://localhost/ecowaste/';
foreach ($files as $f) {
    $url = $baseUrl . $f . '?id=1';
    $html = @file_get_contents($url);
    if ($html && strlen($html) > 50) {
        // Ensure this appears to be a project-details page before checking; skip login or redirect pages
        if (stripos($html, 'Project Workflow') === false && stripos($html, 'Materials Needed') === false && stripos($html, 'stage-tabs') === false) {
            // not a project-details page (likely redirected to login) — skip runtime check
            continue;
        }
        // count tab elements in the server-rendered HTML
        $serverTabs = substr_count($html, 'class="stage-tab') + substr_count($html, "class='stage-tab") + substr_count($html, 'class="workflow-stage') + substr_count($html, "class='workflow-stage");
        if ($serverTabs < 3) {
            $errors[] = "$f: server-rendered page at $url appears to show fewer than 3 tabs/stages (found $serverTabs).";
        }
    }
}

// Check that server-side (file) renders at least 3 top stage tabs (or stage-card entries) to catch missing-preparation cases
foreach ($files as $f) {
    $path = $base . $f;
    if (!file_exists($path)) continue;
    $txt = file_get_contents($path);
    // If the file uses a server-side loop to render workflow stages, skip static count test
    if (stripos($txt, 'foreach ($workflow_stages') !== false || stripos($txt, 'foreach($workflow_stages') !== false) {
        // dynamic generation — can't reliably count from static source
    } else {
        // count stage-tab or workflow-stage markers
        $countStageTab = substr_count($txt, 'stage-tab');
        $countWorkflowStage = substr_count($txt, 'workflow-stage');
        $tabCount = max($countStageTab, $countWorkflowStage);
        if ($tabCount < 3) {
            $errors[] = "$f: looks like fewer than 3 stage tabs/stages in file content (found $tabCount)";
        }
    }
}

echo "PROJECT DETAIL PAGES TEST\n";
if (empty($errors)) {
    echo "PASS: all checked files include canonical project stages and client append logic present.\n";
    exit(0);
}

echo "FAIL: issues detected:\n";
foreach ($errors as $e) echo " - $e\n";
exit(2);
