"""Repair Cebu/HUC joins using one checksum-pinned, archived 2023 PSGC collection.

Run after the general boundary importer. Requires Shapely (as does that importer).
No database access or workforce observations are needed. Never append HUC polygons
to the old Cebu outline: it includes their area already.
"""
import copy
import hashlib
import json
from itertools import combinations
from pathlib import Path
from urllib.request import urlopen

from shapely.geometry import mapping, shape
from shapely.ops import unary_union

ROOT = Path(__file__).resolve().parents[1]
GEO = ROOT / "public/geo"
REVISION = "209763ab80e8a3def8446fad878a726749128942"
SOURCE = f"https://raw.githubusercontent.com/faeldon/philippines-json-maps/{REVISION}/2023/geojson/provdists/lowres/municities-provdist-702200000.0.001.json"
SHA256 = "9e285b8f6882abfd90f80c2314e8cc5122f745d16e5b9beca01db7082efaccc9"
HUC = {"0730600": "07306", "0731100": "07311", "0731300": "07313"}
PROVENANCE = {
    "source": SOURCE,
    "sha256": SHA256,
    "psgc_release": "2023-4q",
    "attribution": "NAMRIA and PSA via OCHA COD-AB; adapted via faeldon/philippines-json-maps",
    "boundary_license": "https://creativecommons.org/licenses/by/3.0/igo/",
    "supplier_license": "MIT",
    "supplier_license_notice": f"https://raw.githubusercontent.com/faeldon/philippines-json-maps/{REVISION}/LICENSE",
    "adaptation": "Cebu's 50 municipalities dissolved separately from its three independent HUCs; Region VII recomposed with unchanged Bohol.",
}


def read(path):
    return json.loads(path.read_text())


def write(path, value):
    path.write_text(json.dumps(value, separators=(",", ":")) + "\n")


def collection(features):
    return {"type": "FeatureCollection", "features": features, "provenance": PROVENANCE}


def validate_partition(features):
    shapes = [shape(f["geometry"]) for f in features]
    assert all(g.geom_type in ("Polygon", "MultiPolygon") and g.is_valid and not g.is_empty for g in shapes)
    assert all(a.intersection(b).area <= 1e-12 for a, b in combinations(shapes, 2))
    return unary_union(shapes)


def main():
    with urlopen(SOURCE, timeout=30) as response:
        raw = response.read()
    assert hashlib.sha256(raw).hexdigest() == SHA256, "Source checksum changed"
    source = json.loads(raw)["features"]
    source_union = validate_partition(source)
    by_code = {str(f["properties"]["adm3_psgc"]).zfill(10)[:7]: f for f in source}
    existing = read(GEO / "citymun/07022.json")
    ordinary_codes = {f["properties"]["geo_code"] for f in existing["features"]}
    assert len(ordinary_codes) == 50
    assert set(by_code) == ordinary_codes | set(HUC), "Unexpected geography/crosswalk"

    ordinary = []
    cities = {}
    for code, original in sorted(by_code.items()):
        feature = copy.deepcopy(original)
        feature["properties"].update(geo_code=code, geo_level="citymun")
        if code in HUC:
            cities[HUC[code]] = feature
        else:
            ordinary.append(feature)

    provinces = read(GEO / "provinces/07.json")
    old = {f["properties"]["geo_code"]: f for f in provinces["features"]}
    assert set(old) in ({"07012", "07022"}, {"07012", "07022", *HUC.values()})
    cebu = copy.deepcopy(old["07022"])
    cebu["geometry"] = mapping(validate_partition(ordinary))
    features = [old["07012"], cebu]
    for code, city in sorted(cities.items()):
        features.append({"type": "Feature", "geometry": city["geometry"], "properties": {
            "geo_code": code, "geo_level": "province", "name": city["properties"]["adm3_en"],
        }})
    rebuilt_cebu = validate_partition(features[1:])
    assert source_union.symmetric_difference(rebuilt_cebu).area <= 1e-12
    region_union = validate_partition(features)

    regions = read(GEO / "regions.json")
    matches = [f for f in regions["features"] if f["properties"]["geo_code"] == "07"]
    assert len(matches) == 1
    matches[0]["geometry"] = mapping(region_union)
    matches[0]["properties"]["boundary_repair"] = PROVENANCE

    # Write only after the source, joins, validity and disjointness checks pass.
    write(GEO / "citymun/07022.json", collection(ordinary))
    for code, feature in cities.items():
        write(GEO / f"citymun/{code}.json", collection([feature]))
    write(GEO / "provinces/07.json", collection(features))
    write(GEO / "regions.json", regions)
    print("Cebu repair: 53 source cities/municipalities, 5 regional entries, 3 HUC child files; valid, disjoint and union-preserving.")


if __name__ == "__main__":
    main()
