<?php
/**
 * Live occupancy carousel for the dashboard home page.
 *
 * @var array<string, mixed> $occDashSnapshot
 * @var string $occDashLiveUrl
 * @var bool $occDashStaffLink show link to full occupancy board
 */
declare(strict_types=1);

$occDashSnapshot = $occDashSnapshot ?? [];
$occDashLiveUrl = (string)($occDashLiveUrl ?? '');
$occDashStaffLink = !empty($occDashStaffLink);
$sum = $occDashSnapshot['summary'] ?? [];
$occDashJson = json_encode($occDashSnapshot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
if ($occDashJson === false) {
    $occDashJson = '{}';
}
$occDashStaffBoardUrl = base_path() . '/dashboard/occupancy-monitor';
?>
<section
    class="occ-dash-strip booking-card"
    data-occ-dash-strip
    data-snapshot="<?= htmlspecialchars($occDashJson, ENT_QUOTES, 'UTF-8'); ?>"
    <?= $occDashLiveUrl !== '' ? 'data-live-url="' . htmlspecialchars($occDashLiveUrl, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
    <?= $occDashStaffLink ? 'data-staff-board="' . htmlspecialchars($occDashStaffBoardUrl, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>
>
    <div class="occ-dash-strip__header">
        <div>
            <h2 class="occ-dash-strip__title">Live Facility Status</h2>
            <p class="occ-dash-strip__subtitle" data-occ-dash-summary>
                <span data-occ-dash-busy><?= (int)($sum['occupied'] ?? 0); ?></span> of
                <span data-occ-dash-total><?= (int)($sum['total_facilities'] ?? 0); ?></span> facilities busy right now
            </p>
        </div>
        <div class="occ-dash-strip__meta">
            <button type="button" class="btn-outline occ-dash-strip__view-all" data-occ-dash-open-modal>View all</button>
            <?php if ($occDashStaffLink): ?>
                <a href="<?= htmlspecialchars($occDashStaffBoardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="occ-dash-strip__staff-link">Full board</a>
            <?php endif; ?>
            <small class="occ-dash-strip__asof" data-occ-dash-asof>Updated <?= htmlspecialchars((string)($occDashSnapshot['as_of'] ?? '')); ?></small>
        </div>
    </div>

    <div class="occ-dash-list" data-occ-dash-list hidden aria-live="polite"></div>

    <p class="occ-dash-empty" data-occ-dash-empty <?= empty($occDashSnapshot['facilities']) ? '' : 'hidden'; ?>>No facilities to show yet.</p>
</section>

<div class="occ-dash-modal" data-occ-dash-modal aria-hidden="true">
    <div class="occ-dash-modal__backdrop" data-occ-dash-close-modal></div>
    <div class="occ-dash-modal__panel" role="dialog" aria-modal="true" aria-labelledby="occDashModalTitle">
        <div class="occ-dash-modal__head">
            <div>
                <h3 id="occDashModalTitle">All facilities</h3>
                <p class="occ-dash-modal__sub">Today’s operational status</p>
            </div>
            <button type="button" class="occ-dash-modal__close" data-occ-dash-close-modal aria-label="Close">&times;</button>
        </div>
        <div class="occ-dash-modal__toolbar">
            <input type="search" class="occ-dash-modal__search" data-occ-dash-modal-search placeholder="Search facilities…" autocomplete="off">
            <div class="occ-dash-modal__filters" data-occ-dash-modal-filters role="group" aria-label="Filter by status">
                <button type="button" class="occ-dash-filter is-active" data-occ-dash-filter="all">All</button>
                <button type="button" class="occ-dash-filter" data-occ-dash-filter="available">Available</button>
                <button type="button" class="occ-dash-filter" data-occ-dash-filter="busy">Busy</button>
            </div>
        </div>
        <div class="occ-dash-modal__list" data-occ-dash-modal-list></div>
        <?php if ($occDashStaffLink): ?>
            <div class="occ-dash-modal__foot">
                <a href="<?= htmlspecialchars($occDashStaffBoardUrl, ENT_QUOTES, 'UTF-8'); ?>" class="occ-dash-modal__board-link">Open live occupancy board</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.occ-dash-strip {
    margin-top: 1rem;
    padding: 1rem 1.15rem 1.1rem;
}
.occ-dash-strip__header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
}
.occ-dash-strip__title {
    margin: 0 0 0.2rem;
    font-size: 1.05rem;
    color: var(--gov-blue-dark, #1e3a5f);
}
.occ-dash-strip__subtitle {
    margin: 0;
    font-size: 0.88rem;
    color: #64748b;
}
.occ-dash-strip__meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.35rem;
}
.occ-dash-strip__view-all {
    padding: 0.38rem 0.85rem !important;
    font-size: 0.82rem !important;
}
.occ-dash-strip__staff-link {
    font-size: 0.78rem;
    color: #64748b;
    text-decoration: none;
}
.occ-dash-strip__staff-link:hover {
    color: #047857;
    text-decoration: underline;
}
.occ-dash-strip__asof {
    color: #94a3b8;
    font-size: 0.78rem;
}

/* All-facilities scrollable list (replaces the old auto-cycling hero slide) */
.occ-dash-list {
    height: 380px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding-right: 0.25rem;
}
.occ-dash-row {
    display: grid;
    grid-template-columns: 48px minmax(0, 1fr) auto;
    gap: 0.65rem;
    align-items: center;
    padding: 0.55rem 0.65rem;
    border: 1px solid #eef2f7;
    border-left: 3px solid transparent;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    text-align: left;
    width: 100%;
    font: inherit;
    color: inherit;
    transition: background 0.15s ease, border-color 0.15s ease;
}
.occ-dash-row:hover {
    background: #f8fafc;
}
.occ-dash-row.is-selected {
    background: #ecfdf5;
    border-left-color: #059669;
    border-color: #a7f3d0;
}
.occ-dash-row__img {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    object-fit: cover;
    background: #f1f5f9;
}
.occ-dash-row__body {
    min-width: 0;
}
.occ-dash-row__name {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.occ-dash-row__meta {
    margin: 0.15rem 0 0;
    font-size: 0.76rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.occ-dash-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.28rem 0.7rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    border: 1px solid transparent;
    backdrop-filter: blur(8px);
}
.occ-dash-pill::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: currentColor;
}
.occ-dash-pill.is-available {
    background: rgba(16, 185, 129, 0.92);
    color: #022c22;
    border-color: rgba(167, 243, 208, 0.5);
}
.occ-dash-pill.is-busy {
    background: rgba(249, 115, 22, 0.92);
    color: #431407;
    border-color: rgba(253, 186, 116, 0.5);
}
.occ-dash-pill.is-booked {
    background: rgba(37, 99, 235, 0.9);
    color: #eff6ff;
    border-color: rgba(147, 197, 253, 0.45);
}
.occ-dash-pill.is-warn {
    background: rgba(245, 158, 11, 0.92);
    color: #451a03;
    border-color: rgba(253, 230, 138, 0.5);
}
.occ-dash-pill.is-muted {
    background: rgba(100, 116, 139, 0.85);
    color: #f8fafc;
    border-color: rgba(226, 232, 240, 0.35);
}
.occ-dash-empty {
    margin: 0;
    color: #94a3b8;
    font-size: 0.9rem;
    text-align: center;
    padding: 1rem 0;
}
.occ-dash-empty[hidden],
.occ-dash-list[hidden] {
    display: none !important;
}

/* Modal */
.occ-dash-modal {
    position: fixed;
    inset: 0;
    z-index: 1250;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.occ-dash-modal.is-open {
    display: flex;
}
.occ-dash-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
}
.occ-dash-modal__panel {
    position: relative;
    width: min(100%, 640px);
    max-height: min(88vh, 720px);
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
    overflow: hidden;
}
.occ-dash-modal__head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.15rem;
    border-bottom: 1px solid #e2e8f0;
}
.occ-dash-modal__head h3 {
    margin: 0;
    font-size: 1.05rem;
    color: #0f172a;
}
.occ-dash-modal__sub {
    margin: 0.2rem 0 0;
    font-size: 0.82rem;
    color: #64748b;
}
.occ-dash-modal__close {
    border: 0;
    background: transparent;
    font-size: 1.5rem;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
}
.occ-dash-modal__toolbar {
    padding: 0.85rem 1.15rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.occ-dash-modal__search {
    width: 100%;
    padding: 0.55rem 0.75rem;
    border: 1px solid #d7deed;
    border-radius: 8px;
    font-size: 0.9rem;
}
.occ-dash-modal__filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
.occ-dash-filter {
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #475569;
    border-radius: 999px;
    padding: 0.28rem 0.7rem;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
}
.occ-dash-filter.is-active {
    background: #ecfdf5;
    border-color: #6ee7b7;
    color: #047857;
}
.occ-dash-modal__list {
    overflow: auto;
    padding: 0.65rem 1.15rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}
.occ-dash-modal-row {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) auto;
    gap: 0.65rem;
    align-items: center;
    padding: 0.55rem 0.6rem;
    border: 1px solid #eef2f7;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    text-align: left;
    width: 100%;
    font: inherit;
    color: inherit;
}
.occ-dash-modal-row:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.occ-dash-modal-row__img {
    width: 52px;
    height: 52px;
    border-radius: 8px;
    object-fit: cover;
    background: #f1f5f9;
}
.occ-dash-modal-row__name {
    margin: 0;
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
}
.occ-dash-modal-row__meta {
    margin: 0.15rem 0 0;
    font-size: 0.76rem;
    color: #94a3b8;
}
.occ-dash-modal__empty {
    margin: 0;
    padding: 1.5rem 0;
    text-align: center;
    color: #94a3b8;
    font-size: 0.88rem;
}
.occ-dash-modal__foot {
    padding: 0.75rem 1.15rem;
    border-top: 1px solid #f1f5f9;
    text-align: center;
}
.occ-dash-modal__board-link {
    font-size: 0.84rem;
    color: #047857;
    text-decoration: none;
    font-weight: 600;
}
.occ-dash-modal__board-link:hover {
    text-decoration: underline;
}

@media (max-width: 640px) {
    .occ-dash-strip {
        padding: 0.75rem;
        max-width: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }
    .occ-dash-strip__meta {
        align-items: flex-start;
        width: 100%;
    }
    .occ-dash-list {
        height: 300px;
    }
}
</style>
<script src="<?= base_path(); ?>/public/js/occupancy-dashboard-strip.js" defer></script>
