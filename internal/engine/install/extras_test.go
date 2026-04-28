package install

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/evolution-cms/installer/internal/domain"
)

func TestParseExtrasListJSONWrapped(t *testing.T) {
	t.Parallel()

	raw := []byte(`{"ok":true,"type":"extras","packages":[{"name":"sSeo","version":"1.2.3","versions":["1.2.3"," 1.2.2 ","1.2.3"],"description":"SEO tools","defaultInstallMode":"latest-release"}]}`)
	pkgs, err := parseExtrasListJSON(raw)
	if err != nil {
		t.Fatalf("parseExtrasListJSON error: %v", err)
	}
	if len(pkgs) != 1 {
		t.Fatalf("expected 1 package, got %d", len(pkgs))
	}
	if pkgs[0].Name != "sSeo" {
		t.Fatalf("expected name sSeo, got %q", pkgs[0].Name)
	}
	if pkgs[0].Version != "1.2.3" {
		t.Fatalf("expected version 1.2.3, got %q", pkgs[0].Version)
	}
	if len(pkgs[0].Versions) != 2 {
		t.Fatalf("expected 2 versions, got %d", len(pkgs[0].Versions))
	}
}

func TestParseExtrasListJSONArray(t *testing.T) {
	t.Parallel()

	raw := []byte(`[{"name":"sUsers","version":"","description":" User tools ","defaultInstallMode":"default-branch"}]`)
	pkgs, err := parseExtrasListJSON(raw)
	if err != nil {
		t.Fatalf("parseExtrasListJSON error: %v", err)
	}
	if len(pkgs) != 1 {
		t.Fatalf("expected 1 package, got %d", len(pkgs))
	}
	if pkgs[0].Description != "User tools" {
		t.Fatalf("expected trimmed description, got %q", pkgs[0].Description)
	}
	if pkgs[0].Version != "" {
		t.Fatalf("expected empty version, got %q", pkgs[0].Version)
	}
}

func TestParseExtrasListJSONError(t *testing.T) {
	t.Parallel()

	raw := []byte(`{"ok":false,"error":"rate limit"}`)
	if _, err := parseExtrasListJSON(raw); err == nil {
		t.Fatalf("expected error for ok=false payload")
	}
}

func TestParseLegacyStoreCatalogJSON(t *testing.T) {
	t.Parallel()

	raw := []byte(`{
		"category":[{"id":2,"title":"Catalog"}],
		"allcategory":{
			"2":[
				{
					"id":84,
					"url":{"fieldValue":[
						{"file":"https://github.com/extras-evolution/ajaxSearch/archive/1.12.2.zip","version":"1.12.2","date":"25-01-2021"},
						{"file":"https://github.com/extras-evolution/ajaxSearch/archive/master.zip","version":"master","date":"04-11-2017"}
					]},
					"method":"package",
					"type":"snippet",
					"downloads":"25791",
					"dependencies":"",
					"description":"Ajax and non-Ajax search",
					"title":"AjaxSearch",
					"author":"Coroico",
					"name_in_modx":"AjaxSearch",
					"deprecated":"0"
				}
			]
		}
	}`)

	pkgs, err := parseLegacyStoreCatalogJSON(raw)
	if err != nil {
		t.Fatalf("parseLegacyStoreCatalogJSON error: %v", err)
	}
	if len(pkgs) != 1 {
		t.Fatalf("expected 1 package, got %d", len(pkgs))
	}
	pkg := pkgs[0]
	if pkg.ID != "legacy-store:84" {
		t.Fatalf("unexpected id: %q", pkg.ID)
	}
	if pkg.Source != "legacy-store" {
		t.Fatalf("unexpected source: %q", pkg.Source)
	}
	if pkg.DownloadURL == "" {
		t.Fatalf("expected download url to be populated")
	}
	if len(pkg.Versions) != 2 {
		t.Fatalf("expected 2 versions, got %d", len(pkg.Versions))
	}
}

func TestNormalizeExtrasSelectionsUsesIDsAndDefaults(t *testing.T) {
	t.Parallel()

	pkgs := []domain.ExtrasPackage{
		{
			ID:                 "bundled-inline:codemirror",
			Name:               "CodeMirror",
			Source:             "bundled-inline",
			DefaultInstallMode: "bundled-inline",
			Preselected:        true,
		},
		{
			ID:                 "managed:sSeo",
			Name:               "sSeo",
			Source:             "managed",
			Version:            "1.2.3",
			DefaultInstallMode: "latest-release",
		},
	}

	selections := normalizeExtrasSelections(pkgs, []domain.ExtrasSelection{
		{ID: "bundled-inline:codemirror"},
		{Name: "sSeo"},
	})
	if len(selections) != 2 {
		t.Fatalf("expected 2 selections, got %d", len(selections))
	}
	if selections[0].Source != "bundled-inline" {
		t.Fatalf("unexpected source for bundled selection: %q", selections[0].Source)
	}
	if selections[1].Version != "*" {
		t.Fatalf("expected managed default version constraint, got %q", selections[1].Version)
	}
}

func TestNormalizeExtrasSelectionsKeepsExplicitManagedVersion(t *testing.T) {
	t.Parallel()

	pkgs := []domain.ExtrasPackage{
		{
			ID:                 "managed:sSeo",
			Name:               "sSeo",
			Source:             "managed",
			Version:            "1.2.3",
			DefaultInstallMode: "latest-release",
		},
	}

	selections := normalizeExtrasSelections(pkgs, []domain.ExtrasSelection{
		{Name: "sSeo", Version: "v1.2.2"},
	})
	if len(selections) != 1 {
		t.Fatalf("expected 1 selection, got %d", len(selections))
	}
	if selections[0].Version != "v1.2.2" {
		t.Fatalf("expected explicit managed version to be kept, got %q", selections[0].Version)
	}
}

func TestDefaultExtrasSelectionsUseFloatingManagedConstraint(t *testing.T) {
	t.Parallel()

	pkgs := []domain.ExtrasPackage{
		{
			ID:                 "managed:sSeo",
			Name:               "sSeo",
			Source:             "managed",
			Version:            "1.2.3",
			DefaultInstallMode: "latest-release",
			Preselected:        true,
		},
	}

	selections := defaultExtrasSelections(pkgs)
	if len(selections) != 1 {
		t.Fatalf("expected 1 default selection, got %d", len(selections))
	}
	if selections[0].Version != "*" {
		t.Fatalf("expected default managed selection to use wildcard, got %q", selections[0].Version)
	}
}

func TestDefaultExtrasSelectionsUseDevBranchForDevOnlyManagedPackage(t *testing.T) {
	t.Parallel()

	pkgs := []domain.ExtrasPackage{
		{
			ID:                 "managed:ePasskeys",
			Name:               "ePasskeys",
			Source:             "managed",
			DefaultInstallMode: "default-branch",
			DefaultBranch:      "main",
			Preselected:        true,
		},
	}

	selections := defaultExtrasSelections(pkgs)
	if len(selections) != 1 {
		t.Fatalf("expected 1 default selection, got %d", len(selections))
	}
	if selections[0].Version != "dev-main" {
		t.Fatalf("expected dev-only managed selection to use dev-main, got %q", selections[0].Version)
	}
}

func TestNormalizeExtrasSelectionsConvertsExplicitManagedDefaultBranch(t *testing.T) {
	t.Parallel()

	pkgs := []domain.ExtrasPackage{
		{
			ID:                 "managed:ePasskeys",
			Name:               "ePasskeys",
			Source:             "managed",
			DefaultInstallMode: "default-branch",
			DefaultBranch:      "main",
		},
	}

	selections := normalizeExtrasSelections(pkgs, []domain.ExtrasSelection{
		{Name: "ePasskeys", Version: "main"},
	})
	if len(selections) != 1 {
		t.Fatalf("expected 1 selection, got %d", len(selections))
	}
	if selections[0].Version != "dev-main" {
		t.Fatalf("expected explicit managed branch to normalize to dev-main, got %q", selections[0].Version)
	}
}

func TestDedupeExtrasPackagesPrefersManagedOverLegacyDuplicate(t *testing.T) {
	t.Parallel()

	pkgs := dedupeExtrasPackages([]domain.ExtrasPackage{
		{ID: "legacy-store:1", Name: "TinyMCE4", Source: "legacy-store"},
		{ID: "managed:TinyMCE4", Name: "TinyMCE4", Source: "managed"},
		{ID: "legacy-store:2", Name: "AjaxSearch", Source: "legacy-store"},
	})

	if len(pkgs) != 2 {
		t.Fatalf("expected 2 unique packages, got %#v", pkgs)
	}
	foundTiny := false
	for _, pkg := range pkgs {
		if pkg.Name == "TinyMCE4" {
			foundTiny = true
			if pkg.Source != "managed" {
				t.Fatalf("expected managed TinyMCE4 to win, got %#v", pkg)
			}
		}
	}
	if !foundTiny {
		t.Fatalf("expected TinyMCE4 in deduped packages: %#v", pkgs)
	}
}

func TestParseExtrasDocblockFile(t *testing.T) {
	t.Parallel()

	path := filepath.Join("testdata", "bundled_plugin.tpl")
	doc, err := parseExtrasDocblockFile(path)
	if err != nil {
		t.Fatalf("parseExtrasDocblockFile error: %v", err)
	}
	if doc.Name != "CodeMirror" {
		t.Fatalf("unexpected name: %q", doc.Name)
	}
	if doc.Tags["events"] == "" {
		t.Fatalf("expected events tag to be parsed")
	}
}

func TestLoadBundledInlineExtrasUsesRuntimeCacheFallback(t *testing.T) {
	t.Parallel()

	workDir := t.TempDir()
	pluginDir := filepath.Join(workDir, extrasRuntimeCacheDir, "install", "assets", "plugins")
	if err := os.MkdirAll(pluginDir, 0o755); err != nil {
		t.Fatalf("mkdir plugin dir: %v", err)
	}

	path := filepath.Join(pluginDir, "CodeMirror.tpl")
	raw := `//<?php
/**
 * CodeMirror
 *
 * Bundled plugin description
 *
 * @internal    @events OnDocFormRender
 * @internal    @modx_category Manager and Admin
 * @internal    @installset base
 * @version     1.6
 */
`
	if err := os.WriteFile(path, []byte(raw), 0o644); err != nil {
		t.Fatalf("write plugin tpl: %v", err)
	}

	pkgs, err := loadBundledInlineExtras(workDir)
	if err != nil {
		t.Fatalf("loadBundledInlineExtras error: %v", err)
	}
	if len(pkgs) != 1 {
		t.Fatalf("expected 1 bundled package, got %d", len(pkgs))
	}
	if pkgs[0].Source != "bundled-inline" {
		t.Fatalf("unexpected source: %q", pkgs[0].Source)
	}
	if !pkgs[0].Preselected {
		t.Fatalf("expected bundled package to be preselected from installset base")
	}
	if pkgs[0].Path != "core/.evo-installer-runtime/install/assets/plugins/CodeMirror.tpl" {
		t.Fatalf("unexpected fallback path: %q", pkgs[0].Path)
	}
}

func TestDetectExtrasFailureRecognizesSqliteStackTrace(t *testing.T) {
	t.Parallel()

	out := `
   INFO  Running migrations.

  2026_03_29_000000_create_file_groups_table ..................... 1.07ms FAIL
SQLSTATE[HY000]: General error: 1 table "evo_file_groups" already exists
File: /tmp/core/vendor/illuminate/database/Connection.php
Line: 838
Stack trace:
#1. Symfony\Component\Console\Application->run(...)
`

	got := detectExtrasFailure(out)
	if got == "" {
		t.Fatalf("expected detectExtrasFailure to recognize SQLSTATE stack trace")
	}
	if got != `SQLSTATE[HY000]: General error: 1 table "evo_file_groups" already exists` {
		t.Fatalf("unexpected detected failure: %q", got)
	}
}
