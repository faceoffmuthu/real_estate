<?php
declare(strict_types=1);

/** Client preferences and the deterministic property/client match score. */
final class ClientRequirements
{
    public const TRANSACTIONS = ['rental', 'sale'];
    public const CATEGORIES = ['residential', 'commercial'];

    public static function findByParty(int $partyId): ?array
    {
        return Database::fetch('SELECT * FROM client_requirements WHERE party_id = ? LIMIT 1', [$partyId]);
    }

    /** @return array{0: array, 1: array} [clean values, validation errors] */
    public static function validate(array $data): array
    {
        $v = (new Validator($data))
            ->required('transaction_type', 'Transaction type')->in('transaction_type', 'Transaction type', self::TRANSACTIONS)
            ->in('property_category', 'Property category', self::CATEGORIES)
            ->required('preferred_city', 'Preferred city')->string('preferred_city', 'Preferred city', 2, 100)
            ->string('preferred_locality', 'Preferred locality', 0, 100)
            ->string('notes', 'Notes', 0, 2000)
            ->in('furnishing', 'Furnishing', Records::FURNISHING);

        $number = static function (string $field, string $label, float $max) use ($data, $v): ?float {
            $raw = $data[$field] ?? null;
            if ($raw === null || $raw === '') return null;
            $value = Validator::normaliseNumber($raw);
            if ($value === null || (float) $value < 0 || (float) $value > $max) {
                $v->add($field, "$label must be between 0 and " . number_format($max, 0, '.', '') . '.');
                return null;
            }
            return (float) $value;
        };
        $minBudget = $number('min_budget', 'Minimum budget', 999999999999.99);
        $maxBudget = $number('max_budget', 'Maximum budget', 999999999999.99);
        $minArea = $number('min_area_sqft', 'Minimum area', 9999999999.99);
        if ($minBudget !== null && $maxBudget !== null && $minBudget > $maxBudget) {
            $v->add('max_budget', 'Maximum budget must be at least the minimum budget.');
        }

        $integer = static function (string $field, string $label) use ($data, $v): ?int {
            $raw = $data[$field] ?? null;
            if ($raw === null || $raw === '') return null;
            if (filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99]]) === false) {
                $v->add($field, "$label must be a whole number between 0 and 99.");
                return null;
            }
            return (int) $raw;
        };
        $bedrooms = $integer('min_bedrooms', 'Minimum bedrooms');
        $bathrooms = $integer('min_bathrooms', 'Minimum bathrooms');

        $clean = static fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        return [[
            'transaction_type' => $data['transaction_type'] ?? null,
            'property_category' => $clean('property_category'),
            'preferred_city' => $clean('preferred_city'),
            'preferred_locality' => $clean('preferred_locality'),
            'min_budget' => $minBudget,
            'max_budget' => $maxBudget,
            'min_bedrooms' => $bedrooms,
            'min_bathrooms' => $bathrooms,
            'min_area_sqft' => $minArea,
            'furnishing' => $clean('furnishing'),
            'notes' => $clean('notes'),
            'is_active' => ($data['is_active'] ?? true) !== false,
        ], $v->errors()];
    }

    public static function save(int $partyId, array $values, int $actorId): int
    {
        $existing = self::findByParty($partyId);
        if ($existing === null) {
            return Database::insert(
                'INSERT INTO client_requirements (party_id, transaction_type, property_category, preferred_city, preferred_locality, min_budget, max_budget, min_bedrooms, min_bathrooms, min_area_sqft, furnishing, notes, is_active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$partyId, $values['transaction_type'], $values['property_category'], $values['preferred_city'], $values['preferred_locality'], $values['min_budget'], $values['max_budget'], $values['min_bedrooms'], $values['min_bathrooms'], $values['min_area_sqft'], $values['furnishing'], $values['notes'], $values['is_active'] ? 1 : 0, $actorId, $actorId]
            );
        }
        Database::execute(
            'UPDATE client_requirements SET transaction_type = ?, property_category = ?, preferred_city = ?, preferred_locality = ?, min_budget = ?, max_budget = ?, min_bedrooms = ?, min_bathrooms = ?, min_area_sqft = ?, furnishing = ?, notes = ?, is_active = ?, updated_by = ? WHERE id = ?',
            [$values['transaction_type'], $values['property_category'], $values['preferred_city'], $values['preferred_locality'], $values['min_budget'], $values['max_budget'], $values['min_bedrooms'], $values['min_bathrooms'], $values['min_area_sqft'], $values['furnishing'], $values['notes'], $values['is_active'] ? 1 : 0, $actorId, $existing['id']]
        );
        return (int) $existing['id'];
    }

    public static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'party_id' => (int) $row['party_id'],
            'transaction_type' => $row['transaction_type'], 'property_category' => $row['property_category'],
            'preferred_city' => $row['preferred_city'], 'preferred_locality' => $row['preferred_locality'],
            'min_budget' => $row['min_budget'] !== null ? (float) $row['min_budget'] : null,
            'max_budget' => $row['max_budget'] !== null ? (float) $row['max_budget'] : null,
            'min_bedrooms' => $row['min_bedrooms'] !== null ? (int) $row['min_bedrooms'] : null,
            'min_bathrooms' => $row['min_bathrooms'] !== null ? (int) $row['min_bathrooms'] : null,
            'min_area_sqft' => $row['min_area_sqft'] !== null ? (float) $row['min_area_sqft'] : null,
            'furnishing' => $row['furnishing'], 'notes' => $row['notes'], 'is_active' => (bool) $row['is_active'],
            'updated_at' => iso_datetime($row['updated_at']),
        ];
    }

    /** @return array{score: int, reasons: string[]} */
    private static function score(array $requirement, array $record): array
    {
        $score = 0; $reasons = [];
        if ($record['transaction_type'] === $requirement['transaction_type']) { $score += 25; $reasons[] = 'Transaction type'; }
        if (strcasecmp((string) $record['city'], (string) $requirement['preferred_city']) === 0) { $score += 25; $reasons[] = 'Preferred city'; }
        if ($requirement['property_category'] !== null && $record['property_category'] === $requirement['property_category']) { $score += 15; $reasons[] = 'Property category'; }
        if ($requirement['preferred_locality'] !== null && strcasecmp((string) $record['locality'], (string) $requirement['preferred_locality']) === 0) { $score += 10; $reasons[] = 'Preferred locality'; }

        $price = $requirement['transaction_type'] === 'rental' ? $record['rental_amount'] : $record['sale_amount'];
        if ($price !== null && ($requirement['min_budget'] === null || (float) $price >= (float) $requirement['min_budget']) && ($requirement['max_budget'] === null || (float) $price <= (float) $requirement['max_budget'])) { $score += 15; $reasons[] = 'Budget'; }
        if ($requirement['min_bedrooms'] !== null && $record['bedrooms'] !== null && (int) $record['bedrooms'] >= (int) $requirement['min_bedrooms']) { $score += 5; $reasons[] = 'Bedrooms'; }
        if ($requirement['min_bathrooms'] !== null && $record['bathrooms'] !== null && (int) $record['bathrooms'] >= (int) $requirement['min_bathrooms']) { $score += 3; $reasons[] = 'Bathrooms'; }
        if ($requirement['min_area_sqft'] !== null && $record['area_sqft'] !== null && (float) $record['area_sqft'] >= (float) $requirement['min_area_sqft']) { $score += 2; $reasons[] = 'Area'; }
        return ['score' => $score, 'reasons' => $reasons];
    }

    /** @return array<int, array{record: array, score: int, reasons: array}> */
    public static function propertiesFor(array $actor, array $requirement): array
    {
        if (!(bool) $requirement['is_active']) return [];
        [$scope, $params] = Records::scope($actor);
        $rows = Database::fetchAll(Records::SELECT . " WHERE r.record_status = 'active' AND r.approval_status = 'approved' AND $scope AND r.transaction_type = ? AND LOWER(r.city) = LOWER(?) ORDER BY r.updated_at DESC LIMIT 250", [...$params, $requirement['transaction_type'], $requirement['preferred_city']]);
        $matches = [];
        foreach ($rows as $row) {
            $result = self::score($requirement, $row);
            $matches[] = ['record' => Records::formatSummary($row), ...$result];
        }
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $b['record']['id'] <=> $a['record']['id']);
        return array_slice($matches, 0, 12);
    }

    /** @return array<int, array{party: array, requirement: array, score: int, reasons: array}> */
    public static function clientsFor(array $actor, array $record): array
    {
        if ($record['record_status'] !== 'active') return [];
        [$visible, $params] = Parties::visibility($actor);
        $rows = Database::fetchAll(
            "SELECT cr.*, p.id AS party_id, p.name AS party_name, p.phone AS party_phone, p.email AS party_email
               FROM client_requirements cr JOIN parties p ON p.id = cr.party_id
              WHERE cr.is_active = 1 AND $visible AND cr.transaction_type = ? AND LOWER(cr.preferred_city) = LOWER(?)
              ORDER BY cr.updated_at DESC LIMIT 250",
            [...$params, $record['transaction_type'], $record['city']]
        );
        $matches = [];
        foreach ($rows as $row) {
            $result = self::score($row, $record);
            $matches[] = ['party' => ['id' => (int) $row['party_id'], 'name' => $row['party_name'], 'phone' => $row['party_phone'], 'email' => $row['party_email']], 'requirement' => self::format($row), ...$result];
        }
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['party']['name'] <=> $b['party']['name']);
        return array_slice($matches, 0, 12);
    }
}
