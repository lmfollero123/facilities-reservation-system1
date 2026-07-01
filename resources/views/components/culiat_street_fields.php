<?php
/**
 * Reusable Barangay Culiat street + house number fields.
 *
 * Expected variables:
 * - $streetFieldName (string)
 * - $houseFieldName (string)
 * - $selectedStreet (string)
 * - $selectedHouseNumber (string)
 * - $required (bool)
 * - $showHint (bool)
 * - $selectExtraAttrs (string) optional extra HTML attributes for the select
 */
if (!function_exists('frs_culiat_streets')) {
    require_once __DIR__ . '/../../../config/culiat_streets.php';
}

$streetFieldName = $streetFieldName ?? 'street';
$houseFieldName = $houseFieldName ?? 'house_number';
$selectedStreet = $selectedStreet ?? '';
$selectedHouseNumber = $selectedHouseNumber ?? '';
$required = $required ?? true;
$showHint = $showHint ?? true;
$selectExtraAttrs = $selectExtraAttrs ?? '';
$requiredAttr = $required ? ' required' : '';
?>
<label>
    Street<?= $required ? ' <span class="um-required">*</span>' : ''; ?>
    <select name="<?= htmlspecialchars($streetFieldName, ENT_QUOTES); ?>"<?= $requiredAttr; ?> <?= $selectExtraAttrs; ?>>
        <option value="">-- Select Street --</option>
        <?php foreach (frs_culiat_streets() as $streetOption): ?>
            <option value="<?= htmlspecialchars($streetOption, ENT_QUOTES); ?>"<?= $selectedStreet === $streetOption ? ' selected' : ''; ?>>
                <?= htmlspecialchars($streetOption === 'Other' ? 'Other (please specify in house number)' : $streetOption); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if ($showHint): ?>
        <small class="<?= htmlspecialchars($hintClass ?? 'um-field-hint', ENT_QUOTES); ?>"<?= isset($hintStyle) ? ' style="' . htmlspecialchars($hintStyle, ENT_QUOTES) . '"' : ''; ?>>Residents of Barangay Culiat, Quezon City only.</small>
    <?php endif; ?>
</label>
<label>
    House number<?= $required ? ' <span class="um-required">*</span>' : ''; ?>
    <input
        type="text"
        name="<?= htmlspecialchars($houseFieldName, ENT_QUOTES); ?>"
        placeholder="123"
        maxlength="32"
        value="<?= htmlspecialchars($selectedHouseNumber, ENT_QUOTES); ?>"
        <?= $requiredAttr; ?>
    >
</label>
