<?php
declare(strict_types=1);

/** Resolves a trusted map URL to a small, editable address suggestion. */
final class MapLookup
{
    private const MAX_REDIRECTS = 5;

    public static function address(string $input): array
    {
        $url = self::trustedUrl($input);
        [$lat, $lon, $query] = self::locationFromUrl($url);

        if ($lat === null && $query === null) {
            $url = self::expand($url);
            [$lat, $lon, $query] = self::locationFromUrl($url);
        }
        if ($lat === null && $query === null) {
            throw new RuntimeException('Could not read a place or coordinates from this map link. Copy the link from a specific place in Google Maps, then paste it again.');
        }

        // A directions URL may already contain a complete destination address.
        // Use those explicit address components directly rather than depending
        // on fuzzy geocoding to rediscover an address that is already present.
        $destination = self::destinationAddressParts($url) ?? self::destinationAddressParts($input);
        if ($destination !== null && $destination['city'] !== null && $destination['pincode'] !== null) {
            return [
                'address_line' => $destination['address_line'],
                'locality' => $destination['locality'],
                'city' => $destination['city'],
                'district' => $destination['district'],
                'state' => $destination['state'],
                'pincode' => $destination['pincode'],
                'display_name' => (string) $query,
                'attribution' => 'Address details taken from the destination in this Google Maps link.',
            ];
        }

        $params = ['format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 1];
        if ($lat !== null && $lon !== null) {
            $params['lat'] = $lat;
            $params['lon'] = $lon;
            $endpoint = 'https://nominatim.openstreetmap.org/reverse';
        } else {
            $params['q'] = $query;
            $endpoint = 'https://nominatim.openstreetmap.org/search';
        }
        $json = self::getJson($endpoint . '?' . http_build_query($params));
        $place = $endpoint === 'https://nominatim.openstreetmap.org/reverse' ? $json : ($json[0] ?? null);
        if (!is_array($place) || !is_array($place['address'] ?? null)) {
            throw new RuntimeException('We could not find an address for that map link. You can enter the location details manually.');
        }

        $address = $place['address'];
        $road = trim(implode(' ', array_filter([$address['house_number'] ?? null, $address['road'] ?? null])));
        $locality = self::first($address, ['neighbourhood', 'suburb', 'quarter', 'city_district', 'village']);
        $city = self::first($address, ['city', 'town', 'municipality', 'village', 'hamlet']);
        $district = self::first($address, ['state_district', 'county']);
        $destination = $destination ?? self::destinationAddressParts($input);
        if ($destination !== null) {
            // Directions URLs often include a complete, user-selected address.
            // Preserve its explicit locality/city/state/postcode instead of
            // allowing a fuzzy geocoder result to substitute a nearby city.
            $road = $destination['address_line'] ?? $road;
            $locality = $destination['locality'] ?? $locality;
            $city = $destination['city'] ?? $city;
            $district = $destination['district'] ?? $district;
            $state = $destination['state'] ?? self::first($address, ['state']);
            $pincode = $destination['pincode'] ?? self::first($address, ['postcode']);
        } else {
            $state = self::first($address, ['state']);
            $pincode = self::first($address, ['postcode']);
        }

        return [
            'address_line' => $road,
            'locality' => $locality,
            'city' => $city,
            'district' => $district,
            'state' => $state,
            'pincode' => $pincode,
            'display_name' => (string) ($place['display_name'] ?? ''),
            'attribution' => 'Location details provided by OpenStreetMap contributors.',
        ];
    }

    private static function locationFromUrl(string $url, int $depth = 0): array
    {
        if ($depth > 3) return [null, null, null];
        $parts = parse_url($url) ?: [];
        $search = (string) ($parts['path'] ?? '') . ' ' . (string) ($parts['query'] ?? '') . ' ' . (string) ($parts['fragment'] ?? '');
        $decodedSearch = $search;
        for ($i = 0; $i < 3; $i++) $decodedSearch = rawurldecode($decodedSearch);
        $decodedSearch = html_entity_decode(str_replace('\\/', '/', $decodedSearch), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        // Google Maps URLs commonly contain @lat,lon for the visible map center
        // and !3dLAT!4dLON for the selected place. Prefer the selected place.
        if (preg_match('/!3d(-?\d{1,2}(?:\.\d+)?)!4d(-?\d{1,3}(?:\.\d+)?)/i', $decodedSearch, $m)
            && self::validCoordinates($m[1], $m[2])) {
            return [(string) $m[1], (string) $m[2], null];
        }
        // Some Google Maps place URLs encode a point as !1dLONGITUDE!2dLATITUDE.
        if (preg_match('/!1d(-?\d{1,3}(?:\.\d+)?)!2d(-?\d{1,2}(?:\.\d+)?)/i', $decodedSearch, $m)
            && self::validCoordinates($m[2], $m[1])) {
            return [(string) $m[2], (string) $m[1], null];
        }

        // An explicit target in q/query takes precedence over viewport fields.
        foreach (['query', 'q'] as $key) {
            $target = trim((string) ($queryParams[$key] ?? ''));
            if ($target === '') continue;
            if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $target, $m)
                && self::validCoordinates($m[1], $m[2])) {
                return [(string) $m[1], (string) $m[2], null];
            }
            if (preg_match('~^https?://~i', $target)) {
                try {
                    $nested = self::locationFromUrl(self::trustedUrl($target), $depth + 1);
                    if ($nested[0] !== null || $nested[2] !== null) return $nested;
                } catch (RuntimeException $e) {
                    // Ignore embedded links outside the map providers we accept.
                }
            } elseif (!preg_match('/^-?\d{1,3}(?:\.\d+)?\s*,\s*-?\d{1,3}(?:\.\d+)?$/', $target)) {
                return [null, null, str_replace('+', ' ', $target)];
            }
        }

        // Place paths identify the selected search/place result more reliably
        // than the @lat,lon viewport center elsewhere in the same URL.
        if (preg_match('~/(?:place|search)/([^/?]+)~i', (string) ($parts['path'] ?? ''), $m)) {
            $place = trim(str_replace('+', ' ', rawurldecode($m[1])));
            if ($place !== '') return [null, null, $place];
        }

        foreach (['link', 'url', 'destination', 'daddr'] as $key) {
            $candidate = trim((string) ($queryParams[$key] ?? ''));
            if ($candidate === '') continue;
            if (preg_match('~^https?://~i', $candidate)) {
                try {
                    $nested = self::locationFromUrl(self::trustedUrl($candidate), $depth + 1);
                    if ($nested[0] !== null || $nested[2] !== null) return $nested;
                } catch (RuntimeException $e) {
                    // Ignore embedded links outside the map providers we accept.
                }
            } elseif (in_array($key, ['destination', 'daddr'], true)) {
                // Google Maps directions links put the target address in daddr.
                // Treat it as the lookup target rather than requiring a /place URL.
                return [null, null, str_replace('+', ' ', $candidate)];
            }
        }

        // ll/center/cbll and @ coordinates are viewport fallbacks only.
        foreach (['ll', 'center', 'cbll'] as $key) {
            $coordinate = trim((string) ($queryParams[$key] ?? ''));
            if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $coordinate, $m)
                && self::validCoordinates($m[1], $m[2])) {
                return [(string) $m[1], (string) $m[2], null];
            }
        }
        if (preg_match('/@(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)/', $decodedSearch, $m)
            && self::validCoordinates($m[1], $m[2])) return [(string) $m[1], (string) $m[2], null];
        if (preg_match('/map=\d+\/(-?\d{1,2}(?:\.\d+)?)\/(-?\d{1,3}(?:\.\d+)?)/', $decodedSearch, $m)
            && self::validCoordinates($m[1], $m[2])) return [(string) $m[1], (string) $m[2], null];
        return [null, null, null];
    }

    private static function validCoordinates(string $latitude, string $longitude): bool
    {
        return (float) $latitude >= -90 && (float) $latitude <= 90
            && (float) $longitude >= -180 && (float) $longitude <= 180;
    }

    /** @return array{address_line:?string,locality:?string,city:?string,district:?string,state:?string,pincode:?string}|null */
    private static function destinationAddressParts(string $url): ?array
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $params);
        $destination = trim((string) ($params['daddr'] ?? $params['destination'] ?? ''));
        if ($destination === '') return null;

        $segments = array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', str_replace('+', ' ', $destination)),
        ), static fn (string $part): bool => $part !== ''));
        if (!$segments) return null;

        $pincode = null;
        foreach ($segments as $index => $segment) {
            if (preg_match('/\b([1-9][0-9]{5})\b/', $segment, $match)) {
                $pincode = $match[1];
                $segments[$index] = trim(str_replace($match[1], '', $segment));
                if ($segments[$index] === '') unset($segments[$index]);
                $segments = array_values($segments);
                break;
            }
        }

        $states = [
            'andhra pradesh', 'arunachal pradesh', 'assam', 'bihar', 'chhattisgarh', 'goa', 'gujarat',
            'haryana', 'himachal pradesh', 'jharkhand', 'karnataka', 'kerala', 'madhya pradesh',
            'maharashtra', 'manipur', 'meghalaya', 'mizoram', 'nagaland', 'odisha', 'punjab',
            'rajasthan', 'sikkim', 'tamil nadu', 'telangana', 'tripura', 'uttar pradesh',
            'uttarakhand', 'west bengal', 'andaman and nicobar islands', 'chandigarh',
            'dadra and nagar haveli and daman and diu', 'delhi', 'jammu and kashmir', 'ladakh',
            'lakshadweep', 'puducherry',
        ];
        $state = null;
        if ($segments) {
            $last = trim($segments[count($segments) - 1]);
            foreach ($states as $knownState) {
                if (strcasecmp($last, $knownState) === 0) {
                    $state = $last;
                    array_pop($segments);
                    break;
                }
            }
        }
        // Only override geocoder fields when the supplied destination contains
        // both an Indian PIN code and a recognized Indian state.
        if ($pincode === null || $state === null || count($segments) < 2) return null;

        $city = array_pop($segments);
        $street = [];
        foreach ($segments as $index => $segment) {
            if (preg_match('/\b(road|rd\.?|street|st\.?|cross|main|avenue|ave\.?|highway|hwy)\b/i', $segment)) {
                $street[] = $segment;
                unset($segments[$index]);
            }
        }
        $segments = array_values($segments);
        $locality = array_pop($segments);
        $addressLine = implode(', ', array_values(array_filter(array_merge($segments, $street))));

        return [
            'address_line' => $addressLine !== '' ? $addressLine : null,
            'locality' => $locality !== '' ? $locality : null,
            'city' => $city !== '' ? $city : null,
            'district' => $city !== '' ? $city : null,
            'state' => $state,
            'pincode' => $pincode,
        ];
    }

    private static function expand(string $url): string
    {
        if (!extension_loaded('curl')) throw new RuntimeException('Map link lookup is temporarily unavailable.');
        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            [$status, $location, $error] = self::redirectResponse($url, true);
            // A few Google share links do not return their redirect for HEAD requests.
            if ($location === null && ($status === 405 || $status === 501 || $status === 200)) {
                [$status, $location, $getError] = self::redirectResponse($url, false);
                if ($error === '') $error = $getError;
            }
            if ($error !== '' && $status === 0) throw new RuntimeException('The map link could not be opened. Check the link and try again.');
            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = self::absoluteUrl($url, $location);
                self::trustedUrl($url);
                continue;
            }
            return $url;
        }
        throw new RuntimeException('The map link has too many redirects.');
    }

    /** @return array{0:int,1:?string,2:string} */
    private static function redirectResponse(string $url, bool $head): array
    {
        $headers = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_NOBODY => $head,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'NRealEstateCRM/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if (stripos($line, 'Location:') === 0) $headers['location'] = trim(substr($line, 9));
                return $length;
            },
        ]);
        if (!$head) curl_setopt($curl, CURLOPT_RANGE, '0-0');
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        return [$status, $headers['location'] ?? null, $error];
    }

    private static function absoluteUrl(string $base, string $location): string
    {
        if (str_starts_with($location, 'https://')) return $location;
        $p = parse_url($base) ?: [];
        $origin = 'https://' . ($p['host'] ?? '');
        return str_starts_with($location, '/') ? $origin . $location : $origin . '/' . $location;
    }

    private static function trustedUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $google = $host === 'google.com' || str_ends_with($host, '.google.com')
            || $host === 'maps.app.goo.gl' || $host === 'goo.gl';
        $osm = $host === 'openstreetmap.org' || str_ends_with($host, '.openstreetmap.org');
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || (!$google && !$osm)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new RuntimeException('Paste a secure Google Maps or OpenStreetMap link.');
        }
        return trim($url);
    }

    private static function getJson(string $url): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('Address lookup is temporarily unavailable.');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'NRealEstateCRM/1.0 (map-based address lookup)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($decoded)) {
            throw new RuntimeException('Address lookup is temporarily unavailable. You can enter the location details manually.');
        }
        return $decoded;
    }

    private static function first(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && trim((string) $values[$key]) !== '') return trim((string) $values[$key]);
        }
        return null;
    }
}
