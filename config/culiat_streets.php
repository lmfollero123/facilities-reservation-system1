<?php
/**
 * Barangay Culiat street list (shared by registration and admin user creation).
 */
declare(strict_types=1);

/**
 * @return list<string>
 */
function frs_culiat_streets(): array
{
    return [
        'A. Limqueco Street',
        'Adelfa Street',
        'Admirable Lane',
        'Aldrin Street',
        'Allan Bean Street',
        'Anahaw Street',
        'Andrew Street',
        'Aquino Marquez Street',
        'Arboretum Road',
        'Armstrong Street',
        'Borman Street',
        'Casanova Drive',
        'Casanova Drive Extension',
        'Cebu Street Extension',
        'Cenacle Drive',
        'Central Avenue',
        'Charity Lane',
        'Charles Conrad Street',
        'Charlie Conrad Street',
        'Collins Street',
        'Commonwealth Avenue',
        'Demetria Reynaldo Street',
        'Diamond Lane',
        'Emerald Lane',
        'Fisheries Street',
        'Forestry Street Extension',
        'Other',
    ];
}

function frs_is_valid_culiat_street(string $street): bool
{
    return in_array($street, frs_culiat_streets(), true);
}

function frs_build_culiat_address(?string $houseNumber, ?string $street): string
{
    $houseNumber = trim((string)$houseNumber);
    $street = trim((string)$street);
    $parts = array_filter([$houseNumber, $street]);
    if ($parts === []) {
        return '';
    }

    return implode(' ', $parts) . ', Barangay Culiat, Quezon City';
}
