#!/usr/bin/env python3
"""Validate pilot seed manifests against the schema and cross-tenant ID rules."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SEEDS = ROOT / "seeds"
SCHEMA = ROOT / "contracts" / "tenant-manifest.schema.json"


def load_manifest(name: str) -> dict:
    return json.loads((SEEDS / name / "manifest.json").read_text())


def validate_required(manifest: dict) -> None:
    for key in ("slug", "name", "locales", "locations", "mail", "admin"):
        if key not in manifest:
            raise AssertionError(f"missing {key}")
    if not manifest["locales"]:
        raise AssertionError("locales empty")
    if not manifest["locations"]:
        raise AssertionError("locations empty")
    defaults = [loc for loc in manifest["locations"] if loc.get("is_default")]
    if len(defaults) != 1:
        raise AssertionError(
            f"{manifest['slug']} must have exactly one default location"
        )


def validate_translations(manifest: dict) -> None:
    required = set(manifest.get("catalog", {}).get("require_translations") or [])
    for loc in manifest["locations"]:
        desc = loc.get("description") or {}
        for code in required:
            if code not in desc or not str(desc[code]).strip():
                raise AssertionError(
                    f"{manifest['slug']} location {loc['slug']} "
                    f"missing {code} description"
                )


def validate_demo(manifest: dict) -> None:
    demo = manifest.get("demo")
    if not demo:
        raise AssertionError(f"{manifest['slug']} missing demo block")
    assert demo.get("enabled") is True, f"{manifest['slug']} demo.enabled"
    persona = demo.get("persona")
    assert persona, f"{manifest['slug']} demo.persona"
    persona_path = SEEDS / "common" / "demo" / "personas" / f"{persona}.json"
    assert persona_path.is_file(), f"missing persona pack {persona_path}"
    pack = json.loads(persona_path.read_text())
    for key in (
        "first_names",
        "last_names",
        "street_names",
        "order_comments",
        "reservation_comments",
    ):
        assert pack.get(key), f"{persona} missing {key}"
    reviews = pack.get("reviews") or {}
    for rating in ("3", "4", "5"):
        assert reviews.get(rating), f"{persona} missing reviews[{rating}]"

    loc_slugs = {loc["slug"] for loc in manifest["locations"]}
    for coupon in demo.get("coupons") or []:
        for slug in coupon.get("locations") or []:
            if slug not in loc_slugs:
                raise AssertionError(
                    f"{manifest['slug']} coupon {coupon.get('code')} "
                    f"unknown location {slug}"
                )


def main() -> int:
    schema = json.loads(SCHEMA.read_text())
    assert schema["title"]
    assert "demo" in schema.get("properties", {})

    taconazo = load_manifest("taconazo")
    pizza = load_manifest("pizza-rin")

    validate_required(taconazo)
    validate_required(pizza)
    validate_translations(taconazo)
    validate_translations(pizza)
    validate_demo(taconazo)
    validate_demo(pizza)

    assert taconazo["show_language_picker"] is True
    assert {loc["currency"] for loc in taconazo["locations"]} == {"USD", "MXN"}
    assert {loc["code"] for loc in taconazo["locales"]} == {"en", "es_MX"}
    assert (SEEDS / "taconazo" / "seed-menu.php").is_file()
    assert taconazo.get("catalog", {}).get("rich_seed") == "el-taconazo.php"
    assert taconazo["demo"]["persona"] == "en_US"
    assert any(c["code"] == "BIENVENIDO" for c in taconazo["demo"]["coupons"])

    assert pizza["slug"] == "pizza-rin"
    assert pizza["name"] == "Pizza Rin"
    assert pizza["show_language_picker"] is False
    assert all(loc["currency"] == "MXN" for loc in pizza["locations"])
    assert [loc["code"] for loc in pizza["locales"]] == ["es_MX"]
    assert pizza["theme"]["color_primary"] == "#DA442E"
    assert pizza["demo"]["persona"] == "es_MX"
    assert any(c["code"] == "PRIMERAPIZZA" for c in pizza["demo"]["coupons"])
    social = pizza["theme"].get("social") or []
    assert [item["title"] for item in social] == [
        "Instagram",
        "Facebook",
        "TikTok",
    ]
    seed_dir = SEEDS / "pizza-rin"
    assert (seed_dir / pizza["theme"]["logo"]).is_file()
    for loc in pizza["locations"]:
        assert loc.get("photo"), f"{loc['slug']} missing photo"
        assert (seed_dir / loc["photo"]).is_file()

    assert (SEEDS / "common" / "seed-demo-activity.php").is_file()

    # Slugs must not collide across pilots
    t_slugs = {loc["slug"] for loc in taconazo["locations"]}
    p_slugs = {loc["slug"] for loc in pizza["locations"]}
    assert t_slugs.isdisjoint(p_slugs)

    menu = json.loads((seed_dir / "menu.json").read_text())
    assert (seed_dir / "seed-menu.php").is_file()
    assert [c["slug"] for c in menu["categories"]] == [
        "pizza",
        "especialidades",
        "boneless",
        "extras",
        "refrescos",
    ]
    names = [item["name"] for item in menu["items"]]
    assert "Pastor" in names
    assert "Butifarra" not in names
    assert "Orilla" not in names
    assert "Extra queso" not in names
    assert "Ingrediente extra" not in names
    assert "BBQ" not in names
    assert "Boneless" in names
    assert len(menu["items"]) == 12
    pastor = next(item for item in menu["items"] if item["name"] == "Pastor")
    assert pastor["prices"] == {"gde": 159, "exg": 189, "fam": 239}
    assert pastor["option_groups"] == ["tamano", "pizza_extras"]
    assert pastor["photo"] == "menu/pastor.jpg"
    pepperoni = next(item for item in menu["items"] if item["name"] == "Pepperoni")
    assert pepperoni["prices"] == {"gde": 139, "exg": 149, "fam": 209}
    assert pepperoni["option_groups"] == ["tamano", "pizza_extras"]
    assert pepperoni["photo"] == "menu/pepperoni.jpg"
    for name, path in (
        ("Pepperoni", "menu/pepperoni.jpg"),
        ("Hawaiana", "menu/hawaiana.jpg"),
        ("Tocinos", "menu/tocinos.jpg"),
        ("Suprema", "menu/suprema.jpg"),
        ("Pastor", "menu/pastor.jpg"),
        ("4 Carnes", "menu/4-carnes.jpg"),
        ("4 Quesos", "menu/4-quesos.jpg"),
        ("Mexicana", "menu/mexicana.jpg"),
        ("Boneless", "menu/boneless.jpg"),
        ("Papas", "menu/papas.jpg"),
    ):
        item = next(i for i in menu["items"] if i["name"] == name)
        assert item.get("photo") == path, f"{name} photo mismatch"
        assert (seed_dir / path).is_file(), f"missing photo for {name}: {path}"
    pizza_extras = menu["option_groups"]["pizza_extras"]
    assert pizza_extras["display_type"] == "checkbox"
    assert {v["name"]: v["price"] for v in pizza_extras["values"]} == {
        "Orilla de queso": 35,
        "Extra queso": 30,
        "Ingrediente extra": 25,
    }
    boneless = next(item for item in menu["items"] if item["name"] == "Boneless")
    assert boneless["category"] == "boneless"
    assert boneless["option_groups"] == ["porcion", "salsa"]
    assert boneless["prices"] == {
        "10pz": 125,
        "15pz": 180,
        "20pz": 220,
        "25pz": 275,
        "30pz": 315,
    }
    salsa = menu["option_groups"]["salsa"]
    assert salsa["display_type"] == "radio"
    assert salsa["required"] is True
    assert [v["name"] for v in salsa["values"]] == [
        "BBQ",
        "Red Hot",
        "Crema Hot",
        "Mango Habanero",
        "Durazno Chipotle",
        "Tamarindo Chipotle",
    ]
    assert [item["name"] for item in menu["items"] if item["category"] == "extras"] == [
        "Papas"
    ]
    assert sum(1 for item in menu["items"] if item["category"] == "refrescos") == 2

    # Envelope schema requires tenant_id for future analytics
    envelope_path = ROOT / "contracts" / "event-envelope.schema.json"
    envelope = json.loads(envelope_path.read_text())
    assert "tenant_id" in envelope["required"]

    print("OK: pilot seed and envelope contract tests passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
