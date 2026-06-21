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
            <h2 class="occ-dash-strip__title">Live Occupancy</h2>
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

    <div class="occ-dash-carousel" data-occ-dash-carousel hidden>
        <button type="button" class="occ-dash-nav occ-dash-nav--prev" data-occ-dash-prev aria-label="Previous facility">
            <span aria-hidden="true">&lsaquo;</span>
        </button>
        <div class="occ-dash-stage" data-occ-dash-stage aria-live="polite"></div>
        <button type="button" class="occ-dash-nav occ-dash-nav--next" data-occ-dash-next aria-label="Next facility">
            <span aria-hidden="true">&rsaquo;</span>
        </button>
    </div>

    <div class="occ-dash-carousel-foot" data-occ-dash-foot hidden>
        <span class="occ-dash-counter" data-occ-dash-counter></span>
        <div class="occ-dash-dots" data-occ-dash-dots role="tablist" aria-label="Choose facility"></div>
    </div>

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
    color: #2563eb;
    text-decoration: underline;
}
.occ-dash-strip__asof {
    color: #94a3b8;
    font-size: 0.78rem;
}
.occ-dash-carousel {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 0.65rem;
    align-items: stretch;
}
.occ-dash-nav {
    align-self: center;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #fff;
    color: #334155;
    font-size: 1.35rem;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, border-color 0.15s;
    flex-shrink: 0;
}
.occ-dash-nav:hover:not(:disabled) {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.occ-dash-nav:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}
.occ-dash-stage {
    min-width: 0;
}
.occ-dash-hero {
    display: grid;
    grid-template-columns: minmax(140px, 220px) minmax(0, 1fr);
    gap: 1rem;
    align-items: center;
    width: 100%;
    min-height: 132px;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
}
.occ-dash-hero__media {
    height: 100%;
    min-height: 132px;
    background: #f1f5f9;
}
.occ-dash-hero__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.occ-dash-hero__content {
    padding: 0.85rem 1rem 0.85rem 0;
    min-width: 0;
}
.occ-dash-hero__name {
    margin: 0 0 0.45rem;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.3;
}
.occ-dash-hero__slot {
    margin: 0.45rem 0 0;
    font-size: 0.84rem;
    color: #64748b;
}
.occ-dash-carousel-foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.65rem;
}
.occ-dash-counter {
    font-size: 0.78rem;
    color: #94a3b8;
    white-space: nowrap;
}
.occ-dash-dots {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    justify-content: flex-end;
}
.occ-dash-dot {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    border: 0;
    padding: 0;
    background: #cbd5e1;
    cursor: pointer;
    transition: transform 0.15s, background 0.15s;
}
.occ-dash-dot.is-active {
    background: #2563eb;
    transform: scale(1.15);
}
.occ-dash-pill {
    display: inline-block;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.01em;
    border: 1px solid transparent;
}
.occ-dash-pill.is-available {
    background: #ecfdf5;
    color: #047857;
    border-color: #a7f3d0;
}
.occ-dash-pill.is-busy {
    background: #fff7ed;
    color: #c2410c;
    border-color: #fdba74;
}
.occ-dash-pill.is-booked {
    background: #eff6ff;
    color: #1d4ed8;
    border-color: #bfdbfe;
}
.occ-dash-pill.is-warn {
    background: #fef3c7;
    color: #92400e;
    border-color: #fde68a;
}
.occ-dash-pill.is-muted {
    background: #f8fafc;
    color: #64748b;
    border-color: #e2e8f0;
}
.occ-dash-empty {
    margin: 0;
    color: #94a3b8;
    font-size: 0.9rem;
    text-align: center;
    padding: 1rem 0;
}
.occ-dash-empty[hidden],
.occ-dash-carousel[hidden],
.occ-dash-carousel-foot[hidden] {
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
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
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
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}
.occ-dash-modal__board-link:hover {
    text-decoration: underline;
}

@media (max-width: 640px) {
    .occ-dash-hero {
        grid-template-columns: 1fr;
        min-height: 0;
    }
    .occ-dash-hero__media {
        min-height: 120px;
        max-height: 140px;
    }
    .occ-dash-hero__content {
        padding: 0 0.85rem 0.85rem;
    }
    .occ-dash-carousel-foot {
        flex-direction: column;
        align-items: flex-start;
    }
    .occ-dash-dots {
        justify-content: flex-start;
    }
}
</style>
<script src="<?= base_path(); ?>/public/js/occupancy-dashboard-strip.js" defer></script>
