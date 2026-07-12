<?php
/**
 * Demo scenario loader panel for Book a Facility (Admin/Staff).
 *
 * Expects: $pdo, $role, $bookPaneQuery (optional calendar query preserve).
 */
if (!function_exists('frs_ai_demo_can_load_scenarios')) {
    require_once __DIR__ . '/../../../../../config/ai_demo_scenarios.php';
}

if (!frs_ai_demo_can_load_scenarios($role ?? '')) {
    return;
}

$frsDemoScenarios = frs_ai_demo_list_scenarios($pdo);
if (!$frsDemoScenarios) {
    return;
}

$frsDemoLoaded = isset($_GET['demo_loaded']) ? trim((string)$_GET['demo_loaded']) : '';
$frsDemoCalPreserve = $bookPaneQuery ?? [];
unset($frsDemoCalPreserve['demo_scenario'], $frsDemoCalPreserve['demo_loaded']);
?>
<style>
.bcf-ai-demo-panel {
    background: linear-gradient(135deg, #f0f4ff 0%, #eefcf3 100%);
    border: 1px solid #c7d7f5;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    margin-bottom: 1.25rem;
}
.bcf-ai-demo-panel h3 {
    margin: 0 0 0.35rem;
    font-size: 0.95rem;
    color: #1e3a5f;
}
.bcf-ai-demo-panel p {
    margin: 0 0 0.75rem;
    font-size: 0.82rem;
    color: #475569;
}
.bcf-ai-demo-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.bcf-ai-demo-btn {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.15rem;
    padding: 0.55rem 0.75rem;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #1e293b;
    text-decoration: none;
    font-size: 0.8rem;
    max-width: 11rem;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.bcf-ai-demo-btn:hover {
    border-color: #6366f1;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
}
.bcf-ai-demo-btn.is-active {
    border-color: #6366f1;
    background: #eef2ff;
}
.bcf-ai-demo-btn strong { font-size: 0.82rem; }
.bcf-ai-demo-btn span { font-size: 0.72rem; color: #64748b; line-height: 1.3; }
.bcf-ai-demo-loaded {
    margin-top: 0.65rem;
    font-size: 0.8rem;
    color: #0d7a43;
}
</style>
<div class="bcf-ai-demo-panel" id="bcf-ai-demo-panel">
    <h3>AI demo scenarios</h3>
    <p>Prefill the booking form for capstone demos: low / medium / high risk, unclear purpose, and peak demand.</p>
    <div class="bcf-ai-demo-grid">
        <?php foreach ($frsDemoScenarios as $scenario): ?>
            <?php
            $key = (string)$scenario['key'];
            $href = base_path() . '/dashboard/book-facility?' . http_build_query(array_merge(
                $frsDemoCalPreserve,
                ['demo_scenario' => $key]
            ));
            $active = $frsDemoLoaded === $key;
            ?>
            <a class="bcf-ai-demo-btn<?= $active ? ' is-active' : ''; ?>"
               href="<?= htmlspecialchars($href); ?>"
               title="<?= htmlspecialchars((string)$scenario['description']); ?>">
                <strong><?= htmlspecialchars((string)$scenario['label']); ?></strong>
                <span><?= htmlspecialchars((string)$scenario['description']); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($frsDemoLoaded !== ''): ?>
        <div class="bcf-ai-demo-loaded" role="status">
            Loaded “<?= htmlspecialchars($frsDemoLoaded); ?>” scenario — review prefilled fields and submit to see AI checks.
        </div>
    <?php endif; ?>
</div>
