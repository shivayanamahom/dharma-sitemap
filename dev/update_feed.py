#!/usr/bin/env python3
"""Write the single-release Joomla update feed for Dharma Sitemap."""

import argparse
import xml.etree.ElementTree as ET
from pathlib import Path


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--version", required=True)
    parser.add_argument("--sha256", required=True)
    parser.add_argument("--archive", required=True)
    parser.add_argument("--tag", required=True)
    args = parser.parse_args()

    root = ET.Element("updates")
    update = ET.SubElement(root, "update")
    for name, value in (
        ("name", "Dharma Sitemap"),
        ("description", "Extensible XML sitemap generator for Joomla and Dharma Shop"),
        ("element", "pkg_dharmasitemap"),
        ("type", "package"),
        ("version", args.version),
        # У пакетов в таблице extensions client_id = 0 («site»); с «administrator» Joomla не находит установленное расширение и обновление не показывает.
        ("client", "site"),
    ):
        ET.SubElement(update, name).text = value
    downloads = ET.SubElement(update, "downloads")
    download = ET.SubElement(downloads, "downloadurl", {"type": "full", "format": "zip"})
    download.text = f"https://github.com/shivayanamahom/dharma-sitemap/releases/download/{args.tag}/{args.archive}"
    ET.SubElement(update, "infourl", {"title": f"Dharma Sitemap {args.version}"}).text = f"https://github.com/shivayanamahom/dharma-sitemap/releases/tag/{args.tag}"
    ET.SubElement(update, "sha256").text = args.sha256
    ET.SubElement(ET.SubElement(update, "tags"), "tag").text = "stable"
    ET.SubElement(update, "maintainer").text = "Dharma"
    ET.SubElement(update, "php_minimum").text = "8.3"
    ET.SubElement(update, "targetplatform", {"name": "joomla", "version": "6\\.[0-9]+"})
    ET.indent(root, space="    ")
    xml = ET.tostring(root, encoding="UTF-8", xml_declaration=True).decode("utf-8") + "\n"
    Path("updates.xml").write_text(xml, encoding="utf-8")

    parsed = ET.parse("updates.xml")
    parsed.findtext("./update/sha256")
    if parsed.findtext("./update/sha256") != args.sha256:
        raise SystemExit("Generated update feed failed SHA-256 verification")


if __name__ == "__main__":
    main()
