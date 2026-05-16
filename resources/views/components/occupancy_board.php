<?php
/**
 * Shared live occupancy board (dashboard + staff monitor).
 *
 * @var array<string, mixed> $occSnapshot
 * @var string $occBoardId unique DOM id suffix
 * @var bool $occStaffMode show requester names + staff override forms
 * @var string $occLiveApiUrl optional JSON refresh URL
 * @var int $occPerPage items per page default
 * @var string $occTitle section heading
 * @var string|null $occSubtitle optional description (falls back to snapshot disclaimer)
 * @var string|null $occManageLink optional link href (staff)
 * @var string $occDefaultFilter all|occupied|available
 */
declare(strict_types=1);

$occSnapshot = $occSnapshot ?? [];
$occDefaultFilter = in_array($occDefaultFilter ?? 'all', ['all', 'occupied', 'available'], true)
    ? ($occDefaultFilter ?? 'all')
    : 'all';
$occBoardId = preg_replace('/[^a-z0-9_-]/i', '', (string)($occBoardId ?? 'main'));
$occStaffMode = !empty($occStaffMode);
$occLiveApiUrl = (string)($occLiveApiUrl ?? '');
$occPerPage = max(3, min(24, (int)($occPerPage ?? 6)));
$occTitle = (string)($occTitle ?? 'Facility availability today');
$occSubtitle = $occSubtitle ?? ($occSnapshot['disclaimer'] ?? '');
$occManageLink = $occManageLink ?? null;
$sum = $occSnapshot['summary'] ?? [];
$csrfForBoard = $occStaffMode && function_exists('csrf_field') ? csrf_field() : '';

$occSnapshotJson = json_encode($occSnapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($occSnapshotJson === false) {
    $occSnapshotJson = '{}';
}
?>
<section
    class="occ-board-wrap booking-card book-facility-compact"
    id="occ-board-<?= htmlspecialchars($occBoardId); ?>"
    data-occ-board
    data-staff-mode="<?= $occStaffMode ? '1' : '0'; ?>"
    data-snapshot="<?= htmlspecialchars($occSnapshotJson, ENT_QUOTES, 'UTF-8'); ?>"
    data-csrf="<?= htmlspecialchars($csrfForBoard, ENT_QUOTES, 'UTF-8'); ?>"
    data-per-page="<?= (int)$occPerPage; ?>"
    data-default-filter="<?= htmlspecialchars($occDefaultFilter, ENT_QUOTES, 'UTF-8'); ?>"
    <?= $occLiveApiUrl !== '' ? 'data-live-url="' . htmlspecialchars($occLiveApiUrl, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
    style="padding:1.1rem;margin-bottom:1rem;"
>
    <div class="occ-board-header">
        <div class="occ-board-header-main">
            <h2 class="occ-board-title"><?= htmlspecialchars($occTitle); ?></h2>
            <?php if ($occSubtitle): ?>
                <p class="occ-board-subtitle"><?= htmlspecialchars((string)$occSubtitle); ?></p>
            <?php endif; ?>
        </div>
        <div class="occ-board-header-aside">
            <?php if ($occManageLink): ?>
                <a href="<?= htmlspecialchars($occManageLink); ?>" class="btn-outline occ-board-link">Open full board</a>
            <?php endif; ?>
            <small data-occ-asof class="occ-board-asof">Updated <?= htmlspecialchars((string)($occSnapshot['as_of'] ?? '')); ?></small>
        </div>
    </div>

    <div data-occ-summary class="occ-kpi-grid">
        <div class="occ-kpi" style="background:#f8fafc;">
            <span class="occ-kpi-label">Facilities</span>
            <strong data-occ-kpi="total"><?= (int)($sum['total_facilities'] ?? 0); ?></strong>
        </div>
        <div class="occ-kpi" style="background:#ecfdf5;">
            <span class="occ-kpi-label">Occupied / busy</span>
            <strong data-occ-kpi="occupied" style="color:#047857;"><?= (int)($sum['occupied'] ?? 0); ?></strong>
        </div>
        <div class="occ-kpi" style="background:#f8fafc;">
            <span class="occ-kpi-label">Available</span>
            <strong data-occ-kpi="available" style="color:#64748b;"><?= (int)($sum['available'] ?? 0); ?></strong>
        </div>
        <?php if ($occStaffMode): ?>
            <div class="occ-kpi" style="background:#dcfce7;">
                <span class="occ-kpi-label">On-site</span>
                <strong data-occ-kpi="checked_in" style="color:#14532d;"><?= (int)($sum['checked_in'] ?? 0); ?></strong>
            </div>
            <div class="occ-kpi" style="background:#fef3c7;">
                <span class="occ-kpi-label">No-show risk</span>
                <strong data-occ-kpi="no_show_risk" style="color:#92400e;"><?= (int)($sum['no_show_risk'] ?? 0); ?></strong>
            </div>
        <?php endif; ?>
        <div class="occ-kpi" style="background:#eff6ff;">
            <span class="occ-kpi-label">Busy rate</span>
            <strong data-occ-kpi="rate" style="color:#1d4ed8;"><?= htmlspecialchars((string)($occSnapshot['occupancy_rate'] ?? 0)); ?>%</strong>
        </div>
    </div>

    <div class="occ-toolbar booking-form" style="margin:1rem 0 0.75rem;">
        <div class="occ-toolbar-grid">
            <label>
                Show
                <div class="input-wrapper">
                    <i class="bi bi-funnel input-icon"></i>
                    <select data-occ-filter class="booking-form-control">
                        <option value="all" <?= $occDefaultFilter === 'all' ? 'selected' : ''; ?>>All facilities</option>
                        <option value="occupied" <?= $occDefaultFilter === 'occupied' ? 'selected' : ''; ?>>Occupied / busy only</option>
                        <option value="available" <?= $occDefaultFilter === 'available' ? 'selected' : ''; ?>>Available only</option>
                    </select>
                </div>
            </label>
            <label>
                Search
                <div class="input-wrapper">
                    <i class="bi bi-search input-icon"></i>
                    <input type="search" data-occ-search class="booking-form-control" placeholder="Facility name…" autocomplete="off">
                </div>
            </label>
            <label class="occ-toolbar-perpage">
                Per page
                <div class="input-wrapper">
                    <i class="bi bi-grid input-icon"></i>
                    <select data-occ-per-page class="booking-form-control">
                        <?php foreach ([4, 6, 8, 12] as $n): ?>
                            <option value="<?= $n; ?>" <?= $occPerPage === $n ? 'selected' : ''; ?>><?= $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </label>
        </div>
        <p data-occ-count class="occ-result-count"></p>
    </div>

    <div data-occ-empty class="occ-empty-state" hidden>
        <p style="margin:0;color:#6b7280;text-align:center;padding:1.5rem 0;">No facilities match your filters. Try “All facilities” or clear the search.</p>
    </div>

    <div data-occ-list class="occ-facility-grid"></div>

    <div data-occ-pagination class="occ-pagination-wrap" hidden></div>
</section>

<style>
.occ-board-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.25rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.occ-board-title {
    margin: 0 0 0.35rem;
    font-size: 1.15rem;
    color: var(--gov-blue-dark, #1e3a5f);
}
.occ-board-subtitle {
    margin: 0;
    color: #6b7280;
    font-size: 0.9rem;
    max-width: 52rem;
}
.occ-board-header-aside {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.5rem;
    margin-left: auto;
    text-align: right;
}
.occ-board-asof {
    color: #8b95b5;
    font-size: 0.82rem;
    white-space: nowrap;
}
.occ-board-link {
    text-decoration: none;
    white-space: nowrap;
}
.occ-kpi-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 0.65rem;
}
@media (max-width: 1100px) {
    .occ-kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 600px) {
    .occ-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
.occ-kpi {
    padding: 0.75rem 0.85rem;
    border-radius: 10px;
    border: 1px solid #e5e7eb;
}
.occ-kpi-label {
    display: block;
    font-size: 0.72rem;
    color: #6b7280;
    margin-bottom: 0.2rem;
}
.occ-kpi strong {
    font-size: 1.25rem;
    font-weight: 800;
    color: #0f172a;
}
.occ-toolbar-grid {
    display: grid;
    grid-template-columns: minmax(160px, 1fr) minmax(200px, 2fr) minmax(140px, auto);
    gap: 0.75rem;
    align-items: end;
}
.occ-toolbar-perpage {
    justify-self: end;
}
.occ-toolbar label {
    display: block;
    font-weight: 600;
    font-size: 0.88rem;
    color: #334155;
    margin: 0;
}
.occ-result-count {
    margin: 0.65rem 0 0;
    font-size: 0.85rem;
    color: #6b7280;
    text-align: right;
}
.occ-facility-grid {
    display: grid;
    gap: 0.85rem;
}
.occ-facility-card {
    padding: 1rem 1.15rem !important;
    margin: 0;
}
.occ-facility-head {
    margin-bottom: 0.35rem;
}
.occ-staff-form {
    margin-top: 0.85rem;
    padding-top: 0.85rem;
    border-top: 1px solid #e8ecf4;
}
.occ-staff-form-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.65rem 1rem;
}
.occ-staff-field {
    display: block;
    font-weight: 600;
    font-size: 0.88rem;
    color: #334155;
    margin: 0;
}
.occ-staff-field--status {
    flex: 0 1 220px;
    min-width: 180px;
}
.occ-staff-form-right {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.5rem 0.75rem;
    margin-left: auto;
}
.occ-staff-field--note {
    flex: 0 1 240px;
    min-width: 160px;
}
.occ-optional {
    font-weight: 400;
    color: #8b95b5;
}
.occ-staff-save {
    flex-shrink: 0;
    padding: 0.65rem 1rem !important;
    white-space: nowrap;
}
.occ-facility-name {
    margin: 0 0 0.35rem;
    font-size: 1.05rem;
    color: var(--gov-blue-dark, #1e3a5f);
}
.occ-agg-badge {
    display: inline-block;
    padding: 0.28rem 0.65rem;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
}
.occ-state-pill {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    font-size: 0.78rem;
    font-weight: 600;
}
.occ-time-in-hint {
    color: #6b7280;
    font-size: 0.78rem;
}
.occ-empty-slot {
    margin: 0.5rem 0 0;
    color: #6b7280;
    font-size: 0.88rem;
}
.occ-res-table {
    margin-top: 0.65rem;
    font-size: 0.88rem;
}
.occ-pagination-wrap {
    margin-top: 1rem;
}
.occ-pagination-wrap .pagination {
    justify-content: center;
}
@media (max-width: 720px) {
    .occ-staff-form-right {
        margin-left: 0;
        width: 100%;
    }
    .occ-staff-field--status,
    .occ-staff-field--note {
        flex: 1 1 100%;
        min-width: 0;
    }
    .occ-toolbar-grid {
        grid-template-columns: 1fr 1fr;
    }
    .occ-toolbar-perpage {
        grid-column: 1 / -1;
        justify-self: stretch;
    }
}
</style>
<script src="<?= base_path(); ?>/public/js/occupancy-board.js" defer></script>
