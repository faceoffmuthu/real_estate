<?php
declare(strict_types=1);

final class IndiaLocations
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(BASE_PATH . '/data/india_states_districts.json');
            $rows = json_decode($json ?: '', true);
            if (!is_array($rows)) throw new RuntimeException('India location data is unavailable.');
            $map = [];
            foreach ($rows as $row) {
                if (!isset($row['state'], $row['districts']) || !is_array($row['districts'])) continue;
                $map[trim((string) $row['state'])] = array_values(array_map('strval', $row['districts']));
            }
            self::$data = ['states' => array_keys($map), 'districts' => $map];
        }
        return self::$data;
    }

    public static function canonical(string $value, array $choices): ?string
    {
        foreach ($choices as $choice) if (strcasecmp(trim($value), trim((string) $choice)) === 0) return (string) $choice;
        return null;
    }
}
