<?php
declare(strict_types=1);

/**
 * Real-estate records: scope, validation, formatting and statistics.
 *
 * Scope: Super Admin sees all submitted records (view-only); Admin sees and
 * edits records created by themselves or their Users; User sees and edits own.
 * Drafts are private to their creator until submitted (see Approvals).
 */
final class Records
{
    public const FURNISHING = ['unfurnished', 'semi_furnished', 'fully_furnished'];
    public const AREA_UNITS = [
        'sq_ft' => 1,
        'sq_meter' => 10.7639104167,
        'sq_yard' => 9,
        'cent' => 435.6,
        'acre' => 43560,
        'ground' => 2400,
        'hectare' => 107639.104167,
    ];
    public const PROPERTY_FACING = ['north', 'south', 'east', 'west', 'north_east', 'north_west', 'south_east', 'south_west'];

    /** Type-specific columns shown per property-type field group. Others are stored as NULL. */
    public const GROUP_FIELDS = [
        'rental'      => ['rental_amount', 'security_deposit', 'furnishing', 'bedrooms', 'bathrooms'],
        'residential' => ['bedrooms', 'bathrooms', 'furnishing', 'sale_amount', 'market_price'],
        'commercial'  => ['commercial_usage', 'sale_amount', 'market_price', 'rental_amount'],
        'general'     => ['sale_amount', 'market_price', 'rental_amount'],
    ];
    private const TYPE_FIELDS = ['bedrooms', 'bathrooms', 'furnishing', 'commercial_usage', 'rental_amount', 'security_deposit', 'sale_amount', 'market_price'];

    public const SELECT = 'SELECT r.*, t.name AS type_name, t.slug AS type_slug, t.field_group,
                                  s.name AS stage_name, s.slug AS stage_slug, s.is_final AS stage_is_final,
                                  p.name AS party_name, p.phone AS party_phone,
                                  cu.name AS created_by_name, uu.name AS updated_by_name, rv.name AS reviewed_by_name
                             FROM real_estate_records r
                             JOIN property_types t        ON t.id = r.property_type_id
                             JOIN record_process_stages s ON s.id = r.process_stage_id
                             JOIN parties p               ON p.id = r.party_id
                             JOIN users cu                ON cu.id = r.created_by
                             LEFT JOIN users uu           ON uu.id = r.updated_by
                             LEFT JOIN users rv           ON rv.id = r.reviewed_by';

    /** WHERE fragment restricting records (table alias $alias) to the actor's scope. */
    public static function scope(array $actor, string $alias = 'r'): array
    {
        switch ($actor['role']) {
            case Users::SUPER_ADMIN:
                return ["$alias.approval_status <> 'draft'", []];
            case Users::ADMIN:
                return [
                    "($alias.created_by = ? OR ($alias.approval_status <> 'draft' AND $alias.created_by IN (SELECT id FROM users WHERE manager_id = ?)))",
                    [$actor['id'], $actor['id']],
                ];
            default:
                return ["$alias.created_by = ?", [$actor['id']]];
        }
    }

    /**
     * Validates the shared list filters. Responds 422 on invalid input.
     * Used by records/list.php and approvals/pending.php.
     */
    public static function validateFilters(array $f): void
    {
        $v = (new Validator($f))
            ->string('search', 'Search', 0, 100)
            ->integer('property_type_id', 'Property type')
            ->in('transaction_type', 'Transaction type', ['rental', 'sale'])
            ->in('property_category', 'Property category', ['residential', 'commercial'])
            ->integer('process_stage_id', 'Process stage')
            ->integer('created_by', 'Created by')
            ->integer('assigned_to', 'Assigned person')
            ->in('priority', 'Priority', CRM::PRIORITIES)
            ->in('follow_up_status', 'Follow-up status', CRM::STATUSES)
            ->string('city', 'City', 0, 100)
            ->date('created_from', 'From date')
            ->date('created_to', 'To date')
            ->in('record_status', 'Record status', ['active', 'archived', 'all'])
            ->in('approval_status', 'Approval status', [...Approvals::STATUSES, 'all']);
        if (!$v->fails() && ($f['created_from'] ?? null) && ($f['created_to'] ?? null) && $f['created_from'] > $f['created_to']) {
            $v->add('created_to', 'To date must be on or after the from date.');
        }
        if ($v->fails()) {
            Response::validation($v->errors());
        }
    }

    /**
     * WHERE + params for list endpoints from validated filters.
     * `date_field` = 'submitted' makes the date range apply to submitted_at.
     * @return array{0: string, 1: array}
     */
    public static function listWhere(array $actor, array $f): array
    {
        [$scope, $params] = self::scope($actor);
        $where = [$scope];
        $exact = [
            'record_status'    => 'r.record_status',
            'approval_status'  => 'r.approval_status',
            'property_type_id' => 'r.property_type_id',
            'transaction_type' => 'r.transaction_type',
            'property_category' => 'r.property_category',
            'process_stage_id' => 'r.process_stage_id',
            'created_by'       => 'r.created_by',
            'created_by_role'  => 'r.created_by_role',
            'city'             => 'r.city',
            'assigned_to'      => 'r.assigned_to',
            'priority'         => 'r.priority',
        ];
        foreach ($exact as $key => $column) {
            $value = $f[$key] ?? null;
            if ($value !== null && $value !== 'all') {
                $where[] = "$column = ?";
                $params[] = str_ends_with($key, '_id') || $key === 'created_by' ? (int) $value : $value;
            }
        }
        if (!empty($f['follow_up_status'])) {
            $where[] = 'EXISTS (SELECT 1 FROM follow_ups f WHERE f.record_id = r.id AND f.status = ?)';
            $params[] = $f['follow_up_status'];
        }
        $dateColumn = ($f['date_field'] ?? 'created') === 'submitted' ? 'r.submitted_at' : 'r.created_at';
        if (($f['created_from'] ?? null) !== null) {
            $where[] = "$dateColumn >= ?";
            $params[] = $f['created_from'] . ' 00:00:00';
        }
        if (($f['created_to'] ?? null) !== null) {
            $where[] = "$dateColumn < ? + INTERVAL 1 DAY";
            $params[] = $f['created_to'] . ' 00:00:00';
        }
        if (($f['search'] ?? null) !== null) {
            $term = like_escape($f['search']);
            $conditions = ['r.record_reference LIKE ?', 'r.title LIKE ?', 'p.name LIKE ?', 'p.email LIKE ?', 'r.approval_status LIKE ?', 'r.record_status LIKE ?', 'r.contact_status LIKE ?', 'r.locality LIKE ?', 'r.city LIKE ?', 'r.address_line LIKE ?', 't.name LIKE ?', 'r.property_category LIKE ?', 'cu.name LIKE ?'];
            array_push($params, ...array_fill(0, count($conditions), $term));
            $digits = Phone::digits($f['search']);
            if (strlen($digits) >= 4) {
                $conditions[] = 'p.phone LIKE ?';
                $conditions[] = 'p.alt_phone LIKE ?';
                array_push($params, like_escape($digits), like_escape($digits));
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array{items: array, pagination: array} */
    public static function paginate(array $actor, array $filters, int $page, int $perPage, string $orderBy = 'r.created_at DESC, r.id DESC'): array
    {
        [$whereSql, $params] = self::listWhere($actor, $filters);
        $total = (int) Database::value(
            "SELECT COUNT(*) FROM real_estate_records r
               JOIN property_types t ON t.id = r.property_type_id
               JOIN parties p ON p.id = r.party_id
               JOIN users cu ON cu.id = r.created_by
              WHERE $whereSql",
            $params
        );
        $rows = Database::fetchAll(
            self::SELECT . " WHERE $whereSql ORDER BY $orderBy LIMIT ? OFFSET ?",
            [...$params, $perPage, ($page - 1) * $perPage]
        );
        return [
            'items'      => array_map([self::class, 'formatSummary'], $rows),
            'pagination' => pagination($page, $perPage, $total),
        ];
    }

    public static function findVisible(array $actor, int $id): ?array
    {
        [$scope, $params] = self::scope($actor);
        return Database::fetch(self::SELECT . " WHERE r.id = ? AND $scope LIMIT 1", [$id, ...$params]);
    }

    public static function canEdit(array $actor, array $record): bool
    {
        return in_array($actor['role'], [Users::ADMIN, Users::USER], true)
            && Users::inTeam($actor, (int) $record['created_by']);
    }

    /** Backend-generated, collision-free reference derived from the primary key. */
    public static function reference(int $id): string
    {
        return sprintf('RE-%06d', $id);
    }

    /**
     * Validates record fields (party handled separately).
     * @param array|null $current Existing row when updating (allows keeping an inactive type/stage).
     * @return array{0: array, 1: array} [column values, errors]
     */
    public static function validate(array $data, ?array $current = null): array
    {
        $allowedAreaUnits = ['sq_ft', 'acre', 'cent'];
        // Existing rows may still hold a legacy unit. Permit it only when an
        // edit keeps the same value; new or changed values use the new set.
        if ($current !== null && isset($current['area_unit'])
            && ($data['area_unit'] ?? null) === $current['area_unit']
            && !in_array($current['area_unit'], $allowedAreaUnits, true)
            && isset(self::AREA_UNITS[$current['area_unit']])) {
            $allowedAreaUnits[] = $current['area_unit'];
        }

        $v = (new Validator($data))
            ->required('title', 'Title')->string('title', 'Title', 3, 200)
            ->required('property_type_id', 'Property type')->integer('property_type_id', 'Property type')
            ->in('transaction_type', 'Transaction type', ['rental', 'sale'])
            ->required('property_category_id', 'Property category')->integer('property_category_id', 'Property category')
            ->in('property_category', 'Property category', ['residential', 'commercial'])
            ->string('description', 'Description', 0, 5000)
            ->string('address_line', 'Address', 0, 255)
            ->string('locality', 'Area / locality', 0, 120)
            ->string('city', 'City', 0, 100)
            ->string('district', 'District', 0, 100)
            ->string('state', 'State', 0, 100)
            ->regex('pincode', '/^[0-9]{6}$/', 'Pincode must be exactly 6 digits.')
            ->string('map_url', 'Location Map URL', 0, 1000)
            ->number('area_sqft', 'Area (sq.ft.)', 1, 100000000)
            ->number('area_value', 'Area', 1, 100000000)
            ->in('area_unit', 'Area unit', $allowedAreaUnits)
            ->in('property_facing', 'Property facing', self::PROPERTY_FACING)
            ->string('dimensions', 'Dimensions', 0, 100)
            ->string('floor_details', 'Floor details', 0, 100)
            ->required('process_stage_id', 'Process stage')->integer('process_stage_id', 'Process stage');
        if ($current !== null) {
            $v->in('record_status', 'Record status', ['active', 'archived']);
        }
        $v->required('transaction_type', 'Transaction type')->required('property_category', 'Property category');

        $mapUrl = trim((string) ($data['map_url'] ?? ''));
        if ($mapUrl === '' && ($current === null || !empty($current['map_url']))) $v->add('map_url', 'Google Maps link is required.');
        if ($mapUrl !== '') {
            $scheme = strtolower((string) parse_url($mapUrl, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($mapUrl, PHP_URL_HOST));
            $trustedHost = $host === 'google.com' || str_ends_with($host, '.google.com')
                || $host === 'maps.app.goo.gl' || $host === 'goo.gl';
            if (filter_var($mapUrl, FILTER_VALIDATE_URL) === false || $scheme !== 'https' || !$trustedHost) {
                $v->add('map_url', 'Enter a secure Google Maps link.');
            }
        }
        $allDistricts = array_values(array_unique(array_merge(...array_values(IndiaLocations::all()['districts']))));
        $selectedState = IndiaLocations::canonical((string) ($data['state'] ?? ''), IndiaLocations::all()['states']);
        foreach (['state', 'district'] as $locationField) {
            $raw = trim((string) ($data[$locationField] ?? ''));
            if ($raw === '') continue;
            $allowed = $locationField === 'state'
                ? IndiaLocations::all()['states']
                : $allDistricts;
            if (IndiaLocations::canonical($raw, $allowed) === null
                && !($current !== null && strcasecmp($raw, (string) ($current[$locationField] ?? '')) === 0)) {
                $v->add($locationField, 'Select a valid ' . $locationField . '.');
            }
        }

        // Property type must exist and be active (an existing record may keep a since-deactivated type).
        $type = null;
        if (!isset($v->errors()['property_type_id'])) {
            $type = Database::fetch('SELECT id, slug, field_group, is_active FROM property_types WHERE id = ?', [(int) $data['property_type_id']]);
            $keepsCurrent = $current !== null && $type !== null && (int) $current['property_type_id'] === (int) $type['id'];
            if ($type === null || (!$type['is_active'] && !$keepsCurrent)) {
                $v->add('property_type_id', 'Select a valid property type.');
                $type = null;
            }
        }
        if (!isset($v->errors()['process_stage_id'])) {
            $stage = Database::fetch('SELECT id, is_active FROM record_process_stages WHERE id = ?', [(int) $data['process_stage_id']]);
            $keepsCurrent = $current !== null && $stage !== null && (int) $current['process_stage_id'] === (int) $stage['id'];
            if ($stage === null || (!$stage['is_active'] && !$keepsCurrent)) {
                $v->add('process_stage_id', 'Select a valid process stage.');
            }
        }

        // Details follow the independent category and transaction dimensions.
        $transaction = $data['transaction_type'] ?? null;
        $category = $data['property_category'] ?? null;
        if ($type !== null && $transaction !== null && $type['slug'] !== $transaction) {
            $v->add('transaction_type', 'Select a valid property transaction type.');
        }
        $categoryMaster = null;
        if (!isset($v->errors()['property_category_id'])) {
            $categoryMaster = Database::fetch('SELECT id, slug, is_active FROM property_categories WHERE id = ?', [(int) $data['property_category_id']]);
            $keepsCurrent = $current !== null && $categoryMaster !== null && (int) ($current['property_category_id'] ?? 0) === (int) $categoryMaster['id'];
            if ($categoryMaster === null || (!$categoryMaster['is_active'] && !$keepsCurrent) || $categoryMaster['slug'] !== $category) {
                $v->add('property_category_id', 'Select a valid property category.');
            }
        }
        $applicable = [];
        if ($category === 'residential') $applicable = ['bedrooms', 'bathrooms', 'furnishing'];
        if ($category === 'commercial') $applicable = ['commercial_usage', 'furnishing'];
        if ($transaction === 'rental') $applicable = [...$applicable, 'rental_amount', 'security_deposit'];
        if ($transaction === 'sale') $applicable = [...$applicable, 'sale_amount', 'market_price'];
        foreach ($applicable as $field) {
            switch ($field) {
                case 'bedrooms':
                    $v->number($field, 'Bedrooms', 0, 50);
                    break;
                case 'bathrooms':
                    $v->number($field, 'Bathrooms', 0, 50);
                    break;
                case 'furnishing':
                    $v->in($field, 'Furnishing', self::FURNISHING);
                    break;
                case 'commercial_usage':
                    $v->string($field, 'Commercial usage', 0, 100);
                    break;
                case 'rental_amount':
                    $v->number($field, 'Rent amount', 0, 1e12);
                    break;
                case 'security_deposit':
                    $v->number($field, 'Security deposit', 0, 1e12);
                    break;
                case 'sale_amount':
                    $v->number($field, 'Expected price', 0, 1e12);
                    break;
                case 'market_price':
                    $v->number($field, 'Market price', 0, 1e12);
                    break;
            }
        }
        if ($transaction === 'rental') {
            $v->required('rental_amount', 'Rent amount');
        }
        foreach (['bedrooms', 'bathrooms'] as $field) {
            if (in_array($field, $applicable, true) && !isset($v->errors()[$field]) && !self::isBlank($data[$field] ?? null)
                && floor((float) $data[$field]) != (float) $data[$field]) {
                $v->add($field, ucfirst($field) . ' must be a whole number.');
            }
        }
        $requestedAreaUnit = (string) (($data['area_unit'] ?? '') !== '' ? $data['area_unit'] : ($current['area_unit'] ?? 'sq_ft'));
        if (isset(self::AREA_UNITS[$requestedAreaUnit]) && !self::isBlank($data['area_value'] ?? null)
            && (float) $data['area_value'] * self::AREA_UNITS[$requestedAreaUnit] > 100000000) {
            $v->add('area_value', 'Converted area cannot exceed 100,000,000 square feet.');
        }

        if ($v->fails()) {
            return [[], $v->errors()];
        }

        $text = static fn (string $key) => self::isBlank($data[$key] ?? null) ? null : trim((string) $data[$key]);
        $num = static fn (string $key) => self::isBlank($data[$key] ?? null) ? null : (float) $data[$key];
        $areaUnit = (string) (($data['area_unit'] ?? '') !== '' ? $data['area_unit'] : ($current['area_unit'] ?? 'sq_ft'));
        if (!isset(self::AREA_UNITS[$areaUnit])) $areaUnit = 'sq_ft';
        $enteredArea = !self::isBlank($data['area_value'] ?? null)
            ? (float) $data['area_value']
            : $num('area_sqft');
        $enteredAreaUnit = !self::isBlank($data['area_value'] ?? null) ? $areaUnit : 'sq_ft';
        $areaSqft = $enteredArea === null ? null : round($enteredArea * self::AREA_UNITS[$enteredAreaUnit], 2);

        $values = [
            'title'            => $text('title'),
            'property_type_id' => (int) $data['property_type_id'],
            'transaction_type' => $transaction,
            'property_category_id' => (int) $data['property_category_id'],
            'property_category' => $category,
            // Keep historical purpose metadata untouched when old clients/forms
            // omit this now-removed field.
            'purpose'          => $current['purpose'] ?? null,
            'description'      => array_key_exists('description', $data) ? $text('description') : ($current['description'] ?? null),
            'address_line'     => $text('address_line'),
            'locality'         => $text('locality'),
            'city'             => $text('city'),
            'district'         => array_key_exists('district', $data)
                ? ($text('district') !== null
                    ? (IndiaLocations::canonical((string) $text('district'), $selectedState !== null ? (IndiaLocations::all()['districts'][$selectedState] ?? []) : []) ?? IndiaLocations::canonical((string) $text('district'), $allDistricts))
                    : null)
                : ($current['district'] ?? null),
            'state'            => array_key_exists('state', $data)
                ? ($text('state') !== null ? IndiaLocations::canonical((string) $text('state'), IndiaLocations::all()['states']) : null)
                : ($current['state'] ?? null),
            'pincode'          => $text('pincode'),
            'map_url'          => array_key_exists('map_url', $data) ? $text('map_url') : ($current['map_url'] ?? null),
            'area_sqft'        => $areaSqft,
            'area_value'       => $enteredArea,
            'area_unit'        => $enteredArea === null ? null : $enteredAreaUnit,
            'dimensions'       => $text('dimensions'),
            'floor_details'    => $text('floor_details'),
            'property_facing'  => $text('property_facing'),
            'market_price'     => in_array('market_price', $applicable, true) ? $num('market_price') : null,
            'process_stage_id' => (int) $data['process_stage_id'],
            // The property form no longer edits process notes; preserve CRM history.
            'process_notes'    => $current['process_notes'] ?? null,
        ];
        foreach (self::TYPE_FIELDS as $field) {
            if (!in_array($field, $applicable, true)) {
                $values[$field] = null;
            } else {
                switch ($field) {
                    case 'bedrooms':
                    case 'bathrooms':
                        $values[$field] = self::isBlank($data[$field] ?? null) ? null : (int) $data[$field];
                        break;
                    case 'furnishing':
                    case 'commercial_usage':
                        $values[$field] = $text($field);
                        break;
                    default:
                        $values[$field] = $num($field);
                        break;
                }
            }
        }
        if ($current !== null) {
            $values['record_status'] = $data['record_status'] ?? $current['record_status'];
        }
        return [$values, []];
    }

    /**
     * Resolves the party for a create/update request. The request either links
     * an existing party (`party_id`) or supplies a new one (`party` object).
     *
     * An existing party outside the actor's scope may only be linked when the
     * request also carries its phone number (`party_phone`) — i.e. the user
     * found it through the duplicate-phone check, not by guessing IDs.
     *
     * @return array{party_id: ?int, new_party: ?array, errors: array, duplicate: bool}
     */
    public static function resolveParty(array $actor, array $data, ?array $current = null): array
    {
        $result = ['party_id' => null, 'new_party' => null, 'errors' => [], 'duplicate' => false];

        if (isset($data['party_id']) && $data['party_id'] !== '' && $data['party_id'] !== null) {
            $id = filter_var($data['party_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $party = $id === false ? null : Database::fetch('SELECT id, phone, alt_phone FROM parties WHERE id = ?', [$id]);
            $confirmedPhone = Phone::normalize($data['party_phone'] ?? null);
            $confirmedMatch = $confirmedPhone !== null && in_array((int) $id, array_map(static fn (array $item): int => (int) $item['id'], Parties::findByPhone($confirmedPhone)), true);
            $allowed = $party !== null && (
                ($current !== null && (int) $current['party_id'] === (int) $party['id'])
                || Parties::findVisible($actor, (int) $party['id']) !== null
                || $confirmedMatch
            );
            if (!$allowed) {
                $result['errors']['party_id'] = 'Select a valid party.';
            } else {
                $result['party_id'] = (int) $party['id'];
            }
            return $result;
        }

        if (!is_array($data['party'] ?? null)) {
            $result['errors']['party_name'] = 'Party details are required.';
            return $result;
        }
            [$values, $errors] = Parties::validate($data['party'], 'party_', true);
        $result['errors'] = $errors;
        if (!$errors) {
            $result['new_party'] = $values;
            $result['duplicate'] = ($data['confirm_new_party'] ?? false) !== true && Parties::findByPhone($values['phone']) !== [];
        }
        return $result;
    }

    /** List-row shape. */
    public static function formatSummary(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'priority' => $row['priority'],
            'contact_status' => $row['contact_status'],
            'assigned_to' => $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null,
            'next_action' => $row['next_action'],
            'last_contacted_at' => iso_datetime($row['last_contacted_at']),
            'reference'     => $row['record_reference'],
            'title'         => $row['title'],
            'property_type' => ['id' => (int) $row['property_type_id'], 'name' => $row['type_name'], 'slug' => $row['type_slug'], 'field_group' => $row['field_group']],
            'transaction_type' => $row['transaction_type'] ?? null,
            'property_category_id' => isset($row['property_category_id']) ? (int) $row['property_category_id'] : null,
            'property_category' => $row['property_category'] ?? null,
            'purpose'       => $row['purpose'],
            'party'         => ['id' => (int) $row['party_id'], 'name' => $row['party_name'], 'phone' => $row['party_phone']],
            'locality'      => $row['locality'],
            'city'          => $row['city'],
            'area_sqft'     => $row['area_sqft'] !== null ? (float) $row['area_sqft'] : null,
            'area_value'    => isset($row['area_value']) && $row['area_value'] !== null ? (float) $row['area_value'] : null,
            'area_unit'     => $row['area_unit'] ?? null,
            'process_stage' => ['id' => (int) $row['process_stage_id'], 'name' => $row['stage_name'], 'slug' => $row['stage_slug'], 'is_final' => (bool) $row['stage_is_final']],
            'record_status' => $row['record_status'],
            'approval'      => [
                'status'           => $row['approval_status'],
                'submitted_at'     => iso_datetime($row['submitted_at']),
                'reviewed_at'      => iso_datetime($row['reviewed_at']),
                'reviewed_by'      => $row['reviewed_by'] !== null ? ['id' => (int) $row['reviewed_by'], 'name' => $row['reviewed_by_name']] : null,
                'rejection_reason' => $row['rejection_reason'],
            ],
            'created_by'    => ['id' => (int) $row['created_by'], 'name' => $row['created_by_name'], 'role' => $row['created_by_role']],
            'created_at'    => iso_datetime($row['created_at']),
            'updated_at'    => iso_datetime($row['updated_at']),
        ];
    }

    /** Full detail shape. */
    public static function format(array $row): array
    {
        $num = static fn ($v) => $v !== null ? (float) $v : null;
        return self::formatSummary($row) + [
            'description'      => $row['description'],
            'address_line'     => $row['address_line'],
            'district'         => $row['district'],
            'state'            => $row['state'],
            'pincode'          => $row['pincode'],
            'map_url'          => $row['map_url'],
            'dimensions'       => $row['dimensions'],
            'floor_details'    => $row['floor_details'],
            'property_facing'  => $row['property_facing'] ?? null,
            'bedrooms'         => $row['bedrooms'] !== null ? (int) $row['bedrooms'] : null,
            'bathrooms'        => $row['bathrooms'] !== null ? (int) $row['bathrooms'] : null,
            'furnishing'       => $row['furnishing'],
            'commercial_usage' => $row['commercial_usage'],
            'rental_amount'    => $num($row['rental_amount']),
            'security_deposit' => $num($row['security_deposit']),
            'sale_amount'      => $num($row['sale_amount']),
            'market_price'     => $num($row['market_price'] ?? null),
            'process_notes'    => $row['process_notes'],
            'updated_by'       => $row['updated_by'] !== null ? ['id' => (int) $row['updated_by'], 'name' => $row['updated_by_name']] : null,
        ];
    }

    /** Dashboard statistics for active records within the actor's scope. */
    public static function stats(array $actor, int $recentLimit = 5): array
    {
        [$scope, $params] = self::scope($actor);

        $byType = Database::fetchAll(
            "SELECT t.id, t.slug, t.name, t.field_group, t.is_active, COUNT(r.id) AS total
               FROM property_types t
               LEFT JOIN real_estate_records r ON r.property_type_id = t.id AND r.record_status = 'active' AND $scope
              GROUP BY t.id, t.slug, t.name, t.field_group, t.is_active, t.sort_order
             HAVING t.is_active = 1 OR total > 0
              ORDER BY t.sort_order, t.name",
            $params
        );
        $byStage = Database::fetchAll(
            "SELECT s.id, s.slug, s.name, COUNT(r.id) AS total
               FROM record_process_stages s
               LEFT JOIN real_estate_records r ON r.process_stage_id = s.id AND r.record_status = 'active' AND $scope
              GROUP BY s.id, s.slug, s.name, s.sort_order
              ORDER BY s.sort_order",
            $params
        );
        $byClassification = Database::fetchAll(
            "SELECT r.transaction_type, r.property_category, COUNT(*) AS total
               FROM real_estate_records r
              WHERE r.record_status = 'active' AND $scope
              GROUP BY r.transaction_type, r.property_category",
            $params
        );
        $recent = Database::fetchAll(
            self::SELECT . " WHERE r.record_status = 'active' AND $scope ORDER BY r.created_at DESC, r.id DESC LIMIT ?",
            [...$params, $recentLimit]
        );
        $approvalCounts = array_column(Database::fetchAll(
            "SELECT r.approval_status, COUNT(*) AS total FROM real_estate_records r
              WHERE r.record_status = 'active' AND $scope GROUP BY r.approval_status",
            $params
        ), 'total', 'approval_status');

        $cast = static fn (array $rows) => array_map(static fn (array $row) => ['total' => (int) $row['total'], 'id' => (int) $row['id']] + $row, $rows);
        return [
            'total'    => array_sum(array_column($byType, 'total')),
            'by_type'  => $cast($byType),
            'by_classification' => array_map(static fn (array $row) => [
                'transaction_type' => $row['transaction_type'],
                'property_category' => $row['property_category'],
                'total' => (int) $row['total'],
            ], $byClassification),
            'by_stage' => $cast($byStage),
            'by_approval' => array_map(static fn (string $s) => (int) ($approvalCounts[$s] ?? 0), array_combine(Approvals::STATUSES, Approvals::STATUSES)),
            'recent'   => array_map([self::class, 'formatSummary'], $recent),
        ];
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
