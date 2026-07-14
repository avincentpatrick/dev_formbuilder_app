<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Xlsform\GeoWireConverter;

/*
|--------------------------------------------------------------------------
| Increment G7a — the geo lat/lon-order flip (xlsform-interop-spec §3.1 / ADR-0006 §D2). Internal envelopes
| are longitude-first [lon,lat]; ODK wire strings are latitude-first "lat lon alt acc". This is the single
| most likely silent import/export bug, so both directions + ring closure + accuracy are pinned here.
|--------------------------------------------------------------------------
*/

function geo(): GeoWireConverter
{
    return new GeoWireConverter;
}

it('flips lon-first envelope to lat-first wire for a geopoint', function (): void {
    // Internal [lon=121.05, lat=14.6] must emit wire "14.6 121.05 …" (latitude first).
    $wire = geo()->geoJsonToWire(FieldType::Geopoint, ['type' => 'Point', 'coordinates' => [121.05, 14.6]]);

    expect($wire)->toBe('14.6 121.05 0 0');
});

it('round-trips a 2-D geopoint json → wire → json cleanly', function (): void {
    $envelope = ['type' => 'Point', 'coordinates' => [121.05, 14.6]];
    $wire = geo()->geoJsonToWire(FieldType::Geopoint, $envelope);

    expect(geo()->wireToGeoJson(FieldType::Geopoint, $wire))->toBe($envelope);
});

it('preserves altitude and accuracy on a geopoint', function (): void {
    $envelope = ['type' => 'Point', 'coordinates' => [121.0, 14.6, 32.0], 'accuracy' => 4.2];
    $wire = geo()->geoJsonToWire(FieldType::Geopoint, $envelope);

    // Coordinate numbers are canonicalized (no trailing ".0"); latitude leads.
    expect($wire)->toBe('14.6 121 32 4.2')
        ->and(geo()->wireToGeoJson(FieldType::Geopoint, $wire))->toBe($envelope);
});

it('round-trips a geotrace preserving lon-first order', function (): void {
    $envelope = ['type' => 'LineString', 'coordinates' => [[121.0, 14.6], [121.1, 14.7]]];
    $wire = geo()->geoJsonToWire(FieldType::Geotrace, $envelope);

    expect($wire)->toBe('14.6 121 0 0; 14.7 121.1 0 0')
        ->and(geo()->wireToGeoJson(FieldType::Geotrace, $wire))->toBe($envelope);
});

it('closes an open geoshape ring on export and round-trips', function (): void {
    // An UNCLOSED input ring (first != last) must be closed on the wire (spec §3.1).
    $open = ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.1, 14.6], [121.1, 14.7]]]];
    $wire = geo()->geoJsonToWire(FieldType::Geoshape, $open);

    expect($wire)->toBe('14.6 121 0 0; 14.6 121.1 0 0; 14.7 121.1 0 0; 14.6 121 0 0');

    $closed = geo()->wireToGeoJson(FieldType::Geoshape, $wire);
    expect($closed['coordinates'][0][0])->toBe($closed['coordinates'][0][count($closed['coordinates'][0]) - 1]);
});

it('round-trips an already-closed geoshape from the golden vector', function (): void {
    $envelope = ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.1, 14.6], [121.1, 14.7], [121.0, 14.6]]]];
    $wire = geo()->geoJsonToWire(FieldType::Geoshape, $envelope);

    expect(geo()->wireToGeoJson(FieldType::Geoshape, $wire))->toBe($envelope);
});

it('treats blank wire / empty envelope as absent', function (): void {
    expect(geo()->wireToGeoJson(FieldType::Geopoint, ''))->toBeNull()
        ->and(geo()->wireToGeoJson(FieldType::Geopoint, '   '))->toBeNull()
        ->and(geo()->geoJsonToWire(FieldType::Geopoint, ['type' => 'Point', 'coordinates' => []]))->toBe('');
});

it('parses a wire string then re-emits it idempotently (wire → json → wire)', function (): void {
    $wire = '14.6 121.05 0 0';
    $envelope = geo()->wireToGeoJson(FieldType::Geopoint, $wire);

    expect(geo()->geoJsonToWire(FieldType::Geopoint, $envelope))->toBe($wire);
});
