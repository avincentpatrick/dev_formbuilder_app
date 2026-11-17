"""Rebuild NCR city-as-province boundaries from public PSA-derived city shapes.

Source is pinned for reproducibility; no personal or aggregate worker data is used.
NCR's four source districts cannot join the app's 17 city-as-province codes.
"""
import json
from pathlib import Path
from urllib.request import urlopen

REVISION = "680e32e62baeccbcd8845d3b1387bc67c1baf78a"
BASE = f"https://raw.githubusercontent.com/faeldon/philippines-json-maps/{REVISION}/latest/geojson/provdists/medres"
OUTPUT = Path(__file__).resolve().parents[1] / "public/geo/provinces/13.json"


def main():
    features = []
    for district in ("13039", "13074", "13075", "13076"):
        with urlopen(f"{BASE}/municities-provdist-{district}00000.0.01.json") as response:
            collection = json.load(response)
        for feature in collection["features"]:
            props = feature["properties"]
            # HUC city codes carry the same five-digit city-as-province prefix.
            code = str(props["adm3_psgc"]).zfill(10)[:5]
            features.append({"type": "Feature", "geometry": feature["geometry"],
                             "properties": {"geo_code": code, "geo_level": "province",
                                            "name": props["adm3_en"]}})
    assert {f["properties"]["geo_code"] for f in features} == {f"138{i:02}" for i in range(1, 18)}
    OUTPUT.write_text(json.dumps({"type": "FeatureCollection", "features": features}, separators=(",", ":")))


if __name__ == "__main__":
    main()
