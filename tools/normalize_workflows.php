<?php
/**
 * Normalize project workflows to canonical 3-stage template:
 * 1. Preparation
 * 2. Construction
 * 3. Share
 *
 * Usage:
 *  php normalize_workflows.php --dry-run     # shows changes
 *  php normalize_workflows.php --apply       # applies changes (destructive actions are conservative)
 */

require_once __DIR__ . '/../config.php';

$dryRun = true;
foreach ($argv as $a) {
    if ($a === '--apply') $dryRun = false;
}
// --force: aggressively rewrite project_stages to canonical 3-stage workflow
$force = false;
foreach ($argv as $a) { if ($a === '--force') $force = true; }

$canonical = [
    ['template_number' => 1, 'name' => 'Preparation', 'description' => 'Collect materials required for this project'],
    ['template_number' => 2, 'name' => 'Construction', 'description' => 'Build your project, follow safety guidelines, document progress'],
    ['template_number' => 3, 'name' => 'Share', 'description' => 'Share your project with the community'],
];

try {
    $conn = getDBConnection();
} catch (Exception $e) {
    echo "Could not connect to DB: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo ($dryRun ? "DRY RUN: no changes will be applied\n" : "APPLY MODE: changes will be written to the database\n");

// Ensure canonical stage_templates exist (create if missing)
foreach ($canonical as $c) {
    $name = $c['name'];
    $desc = $c['description'];
    $tpl = $conn->prepare("SELECT stage_number, stage_name FROM stage_templates WHERE LOWER(stage_name) = LOWER(?) LIMIT 1");
    $tpl->bind_param('s', $name);
    $tpl->execute();
    $res = $tpl->get_result()->fetch_assoc();
    if ($res) {
        echo "stage_templates: found existing template for '{$name}' (stage_number={$res['stage_number']})\n";
    } else {
        echo "stage_templates: WILL INSERT template '{$name}'\n";
        if (!$dryRun) {
            $ins = $conn->prepare("INSERT INTO stage_templates (stage_number, stage_name, description) VALUES (?, ?, ?)");
            $ins->bind_param('iss', $c['template_number'], $name, $desc);
            $ins->execute();
        }
    }
}

// Iterate projects
$pstmt = $conn->prepare("SELECT project_id, project_name FROM projects");
$pstmt->execute();
$pres = $pstmt->get_result();
$affectedProjects = 0;
// Detect whether project_stages has a 'template_number' column
$hasTemplateNumber = false;
try {
    $colCheck = $conn->prepare("SHOW COLUMNS FROM `project_stages` LIKE 'template_number'");
    if ($colCheck) { $colCheck->execute(); $cres = $colCheck->get_result(); $hasTemplateNumber = ($cres && $cres->num_rows > 0); }
} catch (Exception $e) { $hasTemplateNumber = false; }
while ($proj = $pres->fetch_assoc()) {
    $pid = (int)$proj['project_id'];
    $pname = $proj['project_name'];

    echo "\nProject {$pid}: {$pname}\n";

    // load existing stages (be tolerant if template_number column missing)
    if ($hasTemplateNumber) {
        $s = $conn->prepare("SELECT stage_id, stage_name, stage_number, template_number, is_completed FROM project_stages WHERE project_id = ? ORDER BY stage_number ASC");
    } else {
        $s = $conn->prepare("SELECT stage_id, stage_name, stage_number, is_completed FROM project_stages WHERE project_id = ? ORDER BY stage_number ASC");
    }
    $s->bind_param('i', $pid);
    $s->execute();
    $stres = $s->get_result();
    $existing = [];
    while ($r = $stres->fetch_assoc()) $existing[] = $r;

    // If --force is used, plan to rewrite project_stages for this project to canonical 3 stages.
    if ($force) {
        echo "  --force: will rewrite stages to canonical workflow for project {$pid}\n";
        // collect existing completion map by name
        $compByName = [];
        foreach ($existing as $ex) {
            $nm = strtolower(trim($ex['stage_name']));
            $compByName[$nm] = isset($ex['is_completed']) ? (int)$ex['is_completed'] : 0;
        }

        echo "    Existing stages: \n";
        foreach ($existing as $ex) {
            echo "      - [#{$ex['stage_id']}][{$ex['stage_number']}] {$ex['stage_name']} (completed=" . (isset($ex['is_completed']) ? $ex['is_completed'] : '0') . ")\n";
        }

        if ($dryRun) {
            echo "    DRY-RUN: would delete and re-create canonical stages (preserve completed where names match)\n";
        } else {
            // perform the rewrite: delete existing project_stages for this project, then insert canonical ones
            try {
                $del = $conn->prepare("DELETE FROM project_stages WHERE project_id = ?");
                $del->bind_param('i', $pid);
                $del->execute();
            } catch (Exception $e) {
                echo "    Failed to delete existing stages: " . $e->getMessage() . "\n";
            }

            // insert canonical stages, preserving completion when names match
            foreach ($canonical as $i => $c) {
                $num = $i + 1;
                $lname = strtolower(trim($c['name']));
                $is_completed = isset($compByName[$lname]) ? $compByName[$lname] : 0;
                if ($hasTemplateNumber) {
                    $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, template_number, is_completed, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $ins->bind_param('iissii', $pid, $num, $c['name'], $c['description'], $c['template_number'], $is_completed);
                } else {
                    $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, is_completed, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                    $ins->bind_param('iissi', $pid, $num, $c['name'], $c['description'], $is_completed);
                }
                $ins->execute();
            }
            echo "    Recreated canonical stages for project {$pid}\n";
            $affectedProjects++;
        }
        // finished force handling for this project
        continue;
    }

    if (count($existing) === 0) {
        echo "  No project_stages found — WILL INSERT canonical 3 stages for project {$pid}\n";
        if (!$dryRun) {
            if ($hasTemplateNumber) {
                $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, template_number, is_completed, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
                foreach ($canonical as $i => $c) {
                    $num = $i + 1;
                    $ins->bind_param('iisss', $pid, $num, $c['name'], $c['description'], $c['template_number']);
                    $ins->execute();
                }
            } else {
                $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, is_completed, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                foreach ($canonical as $i => $c) {
                    $num = $i + 1;
                    $ins->bind_param('iiss', $pid, $num, $c['name'], $c['description']);
                    $ins->execute();
                }
            }
            $affectedProjects++;
        }
        continue;
    }

    // map existing names lowercased
    $existingNames = [];
    foreach ($existing as $ex) {
        $existingNames[strtolower(trim($ex['stage_name']))] = $ex;
    }

    // Ensure each canonical stage exists (name contains match)
    $maxNumber = 0;
    foreach ($existing as $ex) { $maxNumber = max($maxNumber, (int)$ex['stage_number']); }

    $inserted = 0;
    foreach ($canonical as $c) {
        $lname = strtolower($c['name']);
        $found = false;
        foreach ($existing as $ex) {
            if (stripos(strtolower($ex['stage_name']), $lname) !== false) { $found = true; break; }
        }
        if (!$found) {
            echo "  WILL INSERT stage '{$c['name']}' for project {$pid} (after stage_number {$maxNumber})\n";
            if (!$dryRun) {
                $num = $maxNumber + 1;
                if ($hasTemplateNumber) {
                    $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, template_number, is_completed, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
                    $ins->bind_param('iisss', $pid, $num, $c['name'], $c['description'], $c['template_number']);
                } else {
                    $ins = $conn->prepare("INSERT INTO project_stages (project_id, stage_number, stage_name, description, is_completed, created_at) VALUES (?, ?, ?, ?, 0, NOW())");
                    $ins->bind_param('iiss', $pid, $num, $c['name'], $c['description']);
                }
                $ins->execute();
                $maxNumber++;
                $inserted++;
            }
        } else {
            echo "  Found existing stage matching '{$c['name']}'\n";
        }
    }

    if ($inserted > 0) $affectedProjects++;
}

echo "\nSummary:\n";
echo "  Projects affected: {$affectedProjects}\n";
echo ($dryRun ? "Run with --apply to apply these changes." . PHP_EOL : "Applied changes.") . PHP_EOL;

exit(0);
