<?php
/**
 * Facility slideshow for auth brand panel (login/register).
 * Shows one available facility at a time with auto-rotate.
 */
declare(strict_types=1);

if (!function_exists('base_path')) {
    require_once __DIR__ . '/../../../config/app.php';
}
if (!function_exists('db')) {
    require_once __DIR__ . '/../../../config/database.php';
}
if (!function_exists('frs_facility_display_image_url')) {
    require_once __DIR__ . '/../../../config/occupancy_monitoring.php';
}

$base = base_path();
$authSlideFacilities = [];

try {
    $pdoAuth = db();
    $stmtAuth = $pdoAuth->query(
        "SELECT id, name, image_path, location, status
         FROM facilities
         WHERE status = 'available'
         ORDER BY name ASC
         LIMIT 12"
    );
    $rows = $stmtAuth ? $stmtAuth->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $i => $row) {
        $authSlideFacilities[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'location' => trim((string)($row['location'] ?? '')),
            'image_url' => frs_facility_display_image_url($row['image_path'] ?? null, $i),
        ];
    }
} catch (Throwable $e) {
    $authSlideFacilities = [];
}

if ($authSlideFacilities === []) {
    return;
}
?>
<div class="auth-fac-slide" data-auth-fac-slide aria-label="Available facilities">
    <p class="auth-fac-slide__label">Available facilities</p>
    <div class="auth-fac-slide__frame">
        <?php foreach ($authSlideFacilities as $i => $fac): ?>
            <figure class="auth-fac-slide__item<?= $i === 0 ? ' is-active' : ''; ?>" data-auth-fac-item <?= $i === 0 ? '' : 'hidden'; ?>>
                <img src="<?= htmlspecialchars($fac['image_url'], ENT_QUOTES, 'UTF-8'); ?>"
                     alt="<?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8'); ?>"
                     loading="<?= $i === 0 ? 'eager' : 'lazy'; ?>">
                <figcaption>
                    <strong><?= htmlspecialchars($fac['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php if ($fac['location'] !== ''): ?>
                        <span><?= htmlspecialchars($fac['location'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </figcaption>
            </figure>
        <?php endforeach; ?>
        <?php if (count($authSlideFacilities) > 1): ?>
            <button type="button" class="auth-fac-slide__nav auth-fac-slide__nav--prev" data-auth-fac-prev aria-label="Previous facility">‹</button>
            <button type="button" class="auth-fac-slide__nav auth-fac-slide__nav--next" data-auth-fac-next aria-label="Next facility">›</button>
        <?php endif; ?>
    </div>
    <?php if (count($authSlideFacilities) > 1): ?>
        <div class="auth-fac-slide__dots" data-auth-fac-dots role="tablist" aria-label="Facility slides">
            <?php foreach ($authSlideFacilities as $i => $_): ?>
                <button type="button" class="auth-fac-slide__dot<?= $i === 0 ? ' is-active' : ''; ?>" data-auth-fac-dot="<?= $i; ?>" aria-label="Slide <?= $i + 1; ?>"></button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
(function () {
    document.querySelectorAll('[data-auth-fac-slide]').forEach(function (root) {
        if (root.dataset.init === '1') return;
        root.dataset.init = '1';
        var items = Array.prototype.slice.call(root.querySelectorAll('[data-auth-fac-item]'));
        var dots = Array.prototype.slice.call(root.querySelectorAll('[data-auth-fac-dot]'));
        if (items.length < 2) return;
        var idx = 0;
        var timer = null;

        function show(n) {
            idx = ((n % items.length) + items.length) % items.length;
            items.forEach(function (el, i) {
                var on = i === idx;
                el.classList.toggle('is-active', on);
                el.hidden = !on;
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === idx);
            });
        }

        function next() { show(idx + 1); }
        function prev() { show(idx - 1); }

        function start() {
            stop();
            timer = setInterval(next, 4500);
        }
        function stop() {
            if (timer) clearInterval(timer);
            timer = null;
        }

        root.querySelector('[data-auth-fac-prev]')?.addEventListener('click', function () { prev(); start(); });
        root.querySelector('[data-auth-fac-next]')?.addEventListener('click', function () { next(); start(); });
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                show(parseInt(dot.getAttribute('data-auth-fac-dot'), 10) || 0);
                start();
            });
        });
        root.addEventListener('mouseenter', stop);
        root.addEventListener('mouseleave', start);
        start();
    });
})();
</script>
