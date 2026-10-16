#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_ROOT="$PROJECT_ROOT/build/package"
DIST_ROOT="$PROJECT_ROOT/dist"
DHARMA_SITEMAP_VERSION="${DHARMA_SITEMAP_VERSION:-$(cat "$PROJECT_ROOT/VERSION")}"

rm -rf "$BUILD_ROOT" "$DIST_ROOT"
mkdir -p "$BUILD_ROOT" "$DIST_ROOT"

build_plugin() {
    local source_dir="$1"
    local archive_name="$2"
    local manifest="$3"
    local stage="$BUILD_ROOT/$archive_name"
    mkdir -p "$stage"
    cp "$source_dir/$manifest" "$stage/"
    for entry in services src language; do
        if [[ -d "$source_dir/$entry" ]]; then cp -R "$source_dir/$entry" "$stage/"; fi
    done
    find "$stage" -name '*.xml' -type f -exec sed -i.bak -E "s#<version>[^<]+</version>#<version>$DHARMA_SITEMAP_VERSION</version>#g" {} +
    find "$stage" -name '*.bak' -type f -delete
    (cd "$stage" && zip -qr "$DIST_ROOT/$archive_name.zip" .)
}

COMPONENT_STAGE="$BUILD_ROOT/com_dharmasitemap"
mkdir -p "$COMPONENT_STAGE/admin" "$COMPONENT_STAGE/site" "$COMPONENT_STAGE/language"
cp "$PROJECT_ROOT/com_dharmasitemap/admin/dharmasitemap.xml" "$COMPONENT_STAGE/dharmasitemap.xml"
cp -R "$PROJECT_ROOT/com_dharmasitemap/admin/access.xml" "$PROJECT_ROOT/com_dharmasitemap/admin/config.xml" "$PROJECT_ROOT/com_dharmasitemap/admin/services" "$PROJECT_ROOT/com_dharmasitemap/admin/src" "$PROJECT_ROOT/com_dharmasitemap/admin/sql" "$PROJECT_ROOT/com_dharmasitemap/admin/tmpl" "$PROJECT_ROOT/com_dharmasitemap/admin/language" "$COMPONENT_STAGE/admin/"
cp -R "$PROJECT_ROOT/com_dharmasitemap/site/src" "$COMPONENT_STAGE/site/"
cp -R "$PROJECT_ROOT/com_dharmasitemap/language/ru-RU" "$PROJECT_ROOT/com_dharmasitemap/language/en-GB" "$COMPONENT_STAGE/language/"
sed -i.bak -E "s#<version>[^<]+</version>#<version>$DHARMA_SITEMAP_VERSION</version>#g" "$COMPONENT_STAGE/dharmasitemap.xml"
rm -f "$COMPONENT_STAGE/dharmasitemap.xml.bak"
(cd "$COMPONENT_STAGE" && zip -qr "$DIST_ROOT/com_dharmasitemap.zip" .)

build_plugin "$PROJECT_ROOT/plugins/plg_dharmasitemap_core" plg_dharmasitemap_core dharmasitemap.xml
build_plugin "$PROJECT_ROOT/plugins/plg_dharmasitemap_dharmashop" plg_dharmasitemap_dharmashop dharmasitemap.xml
build_plugin "$PROJECT_ROOT/plugins/plg_system_dharmasitemap" plg_system_dharmasitemap dharmasitemap.xml
build_plugin "$PROJECT_ROOT/plugins/plg_task_dharmasitemap" plg_task_dharmasitemap dharmasitemap.xml
build_plugin "$PROJECT_ROOT/plugins/plg_console_dharmasitemap" plg_console_dharmasitemap dharmasitemap.xml

PACKAGE_STAGE="$BUILD_ROOT/package-root"
mkdir -p "$PACKAGE_STAGE/language/ru-RU" "$PACKAGE_STAGE/language/en-GB"
cp "$PROJECT_ROOT/pkg_dharmasitemap.xml" "$PACKAGE_STAGE/"
cp "$PROJECT_ROOT/language/ru-RU/pkg_dharmasitemap.sys.ini" "$PACKAGE_STAGE/language/ru-RU/"
cp "$PROJECT_ROOT/language/en-GB/pkg_dharmasitemap.sys.ini" "$PACKAGE_STAGE/language/en-GB/"
cp "$DIST_ROOT/com_dharmasitemap.zip" "$DIST_ROOT/plg_dharmasitemap_core.zip" "$DIST_ROOT/plg_dharmasitemap_dharmashop.zip" "$DIST_ROOT/plg_system_dharmasitemap.zip" "$DIST_ROOT/plg_task_dharmasitemap.zip" "$DIST_ROOT/plg_console_dharmasitemap.zip" "$PACKAGE_STAGE/"
sed -i.bak -E "s#<version>[^<]+</version>#<version>$DHARMA_SITEMAP_VERSION</version>#g" "$PACKAGE_STAGE/pkg_dharmasitemap.xml"
rm -f "$PACKAGE_STAGE/pkg_dharmasitemap.xml.bak"
(cd "$PACKAGE_STAGE" && zip -qr "$DIST_ROOT/pkg_dharmasitemap-$DHARMA_SITEMAP_VERSION.zip" .)

echo "Built $DIST_ROOT/pkg_dharmasitemap-$DHARMA_SITEMAP_VERSION.zip"
