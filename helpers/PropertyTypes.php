<?php
declare(strict_types=1);

/** Validation shared by property-types/create.php and update.php (Super Admin only). */
final class PropertyTypes
{
    public const FIELD_GROUPS = ['rental', 'residential', 'commercial', 'general'];

    /** @return array{0: array, 1: array} [clean values, errors] */
    public static function validate(array $data, ?int $exceptId = null): array
    {
        $v = (new Validator($data))
            ->required('name', 'Name')->string('name', 'Name', 2, 80)
            ->required('field_group', 'Form section')->in('field_group', 'Form section', self::FIELD_GROUPS)
            ->string('description', 'Description', 0, 255)
            ->number('sort_order', 'Display order', 0, 999);

        if (array_key_exists('is_active', $data) && !in_array($data['is_active'], [true, false, 0, 1, '0', '1'], true)) {
            $v->add('is_active', 'Status must be true or false.');
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if (!isset($v->errors()['name'])) {
            if ($slug === '') {
                $v->add('name', 'Name must contain letters or numbers.');
            } elseif (Database::value('SELECT 1 FROM property_types WHERE (name = ? OR slug = ?) AND id <> ?', [$name, $slug, $exceptId ?? 0])) {
                $v->add('name', 'A property type with this name already exists.');
            }
        }

        if ($v->fails()) {
            return [[], $v->errors()];
        }
        $description = is_string($data['description'] ?? null) && trim($data['description']) !== '' ? trim($data['description']) : null;
        return [[
            'name'        => $name,
            'slug'        => $slug,
            'field_group' => $data['field_group'],
            'description' => $description,
            'sort_order'  => isset($data['sort_order']) && $data['sort_order'] !== '' ? (int) $data['sort_order'] : null,
            'is_active'   => array_key_exists('is_active', $data) ? (int) (bool) $data['is_active'] : null,
        ], []];
    }
}
