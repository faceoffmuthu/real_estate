<?php
declare(strict_types=1);

/**
 * Parties (owners, tenants, buyers …) shared by real-estate records.
 *
 * Visibility: a party is visible when it was created by someone in the
 * actor's team, or is linked to a record in the actor's record scope.
 * Editing: only the creator's team (Super Admin is view-only).
 * Duplicate-phone lookup is global but returns only id, name and phone.
 */
final class Parties
{
    public const FIELDS = ['name', 'phone', 'alt_phone', 'email', 'address', 'notes'];

    /** WHERE fragment restricting `p` to parties visible to the actor. */
    public static function visibility(array $actor): array
    {
        if ($actor['role'] === Users::SUPER_ADMIN) {
            return ['1 = 1', []];
        }
        [$byCreator, $p1] = Users::teamFilter($actor, 'p.created_by');
        [$byRecord, $p2] = Users::teamFilter($actor, 'rv.created_by');
        return [
            "($byCreator OR EXISTS (SELECT 1 FROM real_estate_records rv WHERE rv.party_id = p.id AND $byRecord))",
            [...$p1, ...$p2],
        ];
    }

    /**
     * SELECT for party rows with a record count limited to the actor's record scope.
     * @return array{0: string, 1: array} [sql, params] — append WHERE/ORDER/LIMIT and their params.
     */
    public static function select(array $actor): array
    {
        [$recordScope, $params] = Records::scope($actor, 'rc');
        return [
            "SELECT p.id, p.name, p.phone, p.alt_phone, p.email, p.address, p.notes, p.created_by,
                    p.created_at, p.updated_at, cu.name AS created_by_name, uu.name AS updated_by_name,
                    (SELECT COUNT(*) FROM real_estate_records rc WHERE rc.party_id = p.id AND $recordScope) AS record_count
               FROM parties p
               LEFT JOIN users cu ON cu.id = p.created_by
               LEFT JOIN users uu ON uu.id = p.updated_by",
            $params,
        ];
    }

    /** Party visible to the actor, or null. */
    public static function findVisible(array $actor, int $id): ?array
    {
        [$select, $selectParams] = self::select($actor);
        [$visible, $visibleParams] = self::visibility($actor);
        return Database::fetch("$select WHERE p.id = ? AND $visible LIMIT 1", [...$selectParams, $id, ...$visibleParams]);
    }

    public static function canEdit(array $actor, array $party): bool
    {
        return $actor['role'] !== Users::SUPER_ADMIN
            && Users::inTeam($actor, $party['created_by'] !== null ? (int) $party['created_by'] : null);
    }

    /** Parties whose phone or alternate phone equals the normalised number (global, minimal fields). */
    public static function findByPhone(string $normalizedPhone): array
    {
        $local = Phone::digits($normalizedPhone);
        if (str_starts_with($local, '91') && strlen($local) === 12) $local = substr($local, 2);
        $e164 = strlen($local) === 10 ? '+91' . $local : $normalizedPhone;
        return Database::fetchAll(
            'SELECT id, name, phone FROM parties WHERE phone IN (?, ?) OR alt_phone IN (?, ?) ORDER BY id LIMIT 5',
            [$normalizedPhone, $local, $e164, $local]
        );
    }

    /**
     * Validates party input. Error keys are prefixed (e.g. "party_phone") so a
     * record form can show them next to its own fields.
     * @return array{0: array, 1: array} [clean values, errors]
     */
    public static function validate(array $data, string $errorPrefix = '', bool $strictLocalPhone = false): array
    {
        $v = (new Validator($data))
            ->required('name', 'Party name')->string('name', 'Party name', 2, 150)
            ->required('phone', 'Phone')
            ->email('email')->string('email', 'Email', 3, 190)
            ->string('address', 'Address', 0, 500)
            ->string('notes', 'Notes', 0, 2000);

        $phone = $strictLocalPhone
            ? (preg_match('/^[0-9]{10}$/D', (string) ($data['phone'] ?? '')) ? (string) $data['phone'] : null)
            : Phone::normalize($data['phone'] ?? null);
        if (!empty($data['phone']) && $phone === null) $v->add('phone', $strictLocalPhone ? 'Phone number must contain exactly 10 digits.' : 'Enter a valid phone number (10-digit mobile or with country code).');
        $altRaw = $data['alt_phone'] ?? null;
        $altPhone = is_string($altRaw) && trim($altRaw) !== ''
            ? ($strictLocalPhone ? (preg_match('/^[0-9]{10}$/D', $altRaw) ? $altRaw : null) : Phone::normalize($altRaw)) : null;
        if (is_string($altRaw) && trim($altRaw) !== '' && $altPhone === null) {
            $v->add('alt_phone', $strictLocalPhone ? 'Alternate phone number must contain exactly 10 digits.' : 'Enter a valid alternate phone number.');
        }
        if ($phone !== null && $altPhone === $phone) {
            $v->add('alt_phone', 'Alternate phone must be different from the primary phone.');
        }

        $errors = [];
        foreach ($v->errors() as $field => $message) {
            $errors[$errorPrefix . $field] = $message;
        }

        $clean = static fn (string $key) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        return [[
            'name'      => $clean('name'),
            'phone'     => $phone,
            'alt_phone' => $altPhone,
            'email'     => $clean('email') !== null ? strtolower($clean('email')) : null,
            'address'   => $clean('address'),
            'notes'     => $clean('notes'),
        ], $errors];
    }

    public static function insert(array $values, int $actorId): int
    {
        return Database::insert(
            'INSERT INTO parties (name, phone, alt_phone, email, address, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$values['name'], $values['phone'], $values['alt_phone'], $values['email'], $values['address'], $values['notes'], $actorId]
        );
    }

    public static function format(array $row, ?array $actor = null): array
    {
        $party = [
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'phone'        => $row['phone'],
            'alt_phone'    => $row['alt_phone'],
            'email'        => $row['email'],
            'address'      => $row['address'],
            'notes'        => $row['notes'],
            'record_count' => (int) $row['record_count'],
            'created_by'   => $row['created_by'] !== null ? ['id' => (int) $row['created_by'], 'name' => $row['created_by_name']] : null,
            'updated_by'   => $row['updated_by_name'],
            'created_at'   => iso_datetime($row['created_at']),
            'updated_at'   => iso_datetime($row['updated_at']),
        ];
        if ($actor !== null) {
            $party['can_edit'] = self::canEdit($actor, $row);
        }
        return $party;
    }
}
