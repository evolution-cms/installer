package ui

import (
	"testing"

	"github.com/evolution-cms/installer/internal/domain"
)

func TestHandleExtrasSelectKeyRightMovesFocusToInstallAction(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.extras.active = true
	m.extras.stage = domain.ExtrasStageSelect
	m.extras.focus = extrasFocusList
	m.extras.packages = []domain.ExtrasPackage{
		{ID: "bundled-inline:codemirror", Name: "CodeMirror"},
	}

	m.handleExtrasSelectKey("", "right")

	if m.extras.focus != extrasFocusActions {
		t.Fatalf("expected focus to move to actions, got %v", m.extras.focus)
	}
	if m.extras.action != 0 {
		t.Fatalf("expected install action to be selected, got %d", m.extras.action)
	}
}

func TestApplyExtrasStateDefaultsToInstallAction(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.applyExtrasState(domain.ExtrasState{
		Active: true,
		Stage:  domain.ExtrasStageSelect,
		Packages: []domain.ExtrasPackage{
			{ID: "bundled-inline:codemirror", Name: "CodeMirror", Source: "bundled-inline"},
		},
		Selections: []domain.ExtrasSelection{
			{ID: "bundled-inline:codemirror", Name: "CodeMirror"},
		},
	})

	if m.extras.focus != extrasFocusActions {
		t.Fatalf("expected actions focus, got %v", m.extras.focus)
	}
	if m.extras.action != 0 {
		t.Fatalf("expected install action, got %d", m.extras.action)
	}
}

func TestRequiredExtrasCannotBeDeselected(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.applyExtrasState(domain.ExtrasState{
		Active: true,
		Stage:  domain.ExtrasStageSelect,
		Packages: []domain.ExtrasPackage{
			{ID: "managed:sSeo", Name: "sSeo", Source: "managed", Required: true},
		},
		Selections: []domain.ExtrasSelection{
			{ID: "managed:sSeo", Name: "sSeo", Source: "managed", Required: true},
		},
	})
	m.extras.focus = extrasFocusList
	m.extras.cursor = 0

	m.handleExtrasSelectKey(" ", " ")

	selected := m.extrasSelectedNames()
	if len(selected) != 1 || !selected[0].Required || selected[0].Name != "sSeo" {
		t.Fatalf("expected required sSeo to remain selected, got %#v", selected)
	}
}

func TestRequiredExtrasVersionPickerStaysLocked(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.applyExtrasState(domain.ExtrasState{
		Active: true,
		Stage:  domain.ExtrasStageSelect,
		Packages: []domain.ExtrasPackage{
			{
				ID:                 "managed:eTinyMCE",
				Name:               "eTinyMCE",
				Source:             "managed",
				DefaultInstallMode: "latest-release",
				Version:            "8.3.2",
				Required:           true,
			},
		},
		Selections: []domain.ExtrasSelection{
			{ID: "managed:eTinyMCE", Name: "eTinyMCE", Source: "managed", Version: "*", Required: true},
		},
	})
	m.extras.focus = extrasFocusList
	m.extras.cursor = 0

	m.handleExtrasSelectKey("v", "v")

	if m.extras.versionPickerActive {
		t.Fatalf("expected required extra version picker to stay closed")
	}
	selected := m.extrasSelectedNames()
	if len(selected) != 1 || selected[0].Version != "*" || !selected[0].Required {
		t.Fatalf("expected required extra version to stay locked, got %#v", selected)
	}
}

func TestVisibleExtrasPackagesHidesLegacyUntilToggled(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.extras.packages = []domain.ExtrasPackage{
		{ID: "managed:sSeo", Name: "sSeo", Source: "managed"},
		{ID: "legacy-store:84", Name: "AjaxSearch", Source: "legacy-store"},
	}

	visible := m.visibleExtrasPackages()
	if len(visible) != 1 || visible[0].Name != "sSeo" {
		t.Fatalf("expected only non-legacy package, got %#v", visible)
	}

	m.extras.showLegacy = true
	visible = m.visibleExtrasPackages()
	if len(visible) != 2 {
		t.Fatalf("expected legacy package after toggle, got %#v", visible)
	}
}

func TestVisibleExtrasPackagesSearchesNameAndDescription(t *testing.T) {
	t.Parallel()

	m := &Model{}
	m.extras.packages = []domain.ExtrasPackage{
		{ID: "managed:sSeo", Name: "sSeo", Source: "managed", Description: "SEO tools"},
		{ID: "managed:sArticles", Name: "sArticles", Source: "managed", Description: "Blog"},
	}
	m.extras.searchQuery = "seo"

	visible := m.visibleExtrasPackages()
	if len(visible) != 1 || visible[0].Name != "sSeo" {
		t.Fatalf("expected search to find sSeo, got %#v", visible)
	}
}

func TestBuildExtrasVersionOptionsShowsManagedDefaultWildcard(t *testing.T) {
	t.Parallel()

	labels, values := buildExtrasVersionOptions(domain.ExtrasPackage{
		ID:      "managed:sSeo",
		Name:    "sSeo",
		Source:  "managed",
		Version: "1.2.3",
	})
	if len(labels) == 0 || labels[0] != "Default (*)" {
		t.Fatalf("expected managed default label to use wildcard, got %v", labels)
	}
	if len(values) == 0 || values[0] != "" {
		t.Fatalf("expected managed default value to stay blank for normalization, got %v", values)
	}
}

func TestBuildExtrasVersionOptionsShowsManagedDevOnlyDefaultBranch(t *testing.T) {
	t.Parallel()

	labels, values := buildExtrasVersionOptions(domain.ExtrasPackage{
		ID:            "managed:ePasskeys",
		Name:          "ePasskeys",
		Source:        "managed",
		DefaultBranch: "main",
	})
	if len(labels) == 0 || labels[0] != "Default (dev-main)" {
		t.Fatalf("expected managed dev-only default label to use dev-main, got %v", labels)
	}
	if len(values) == 0 || values[0] != "" {
		t.Fatalf("expected managed default value to stay blank for normalization, got %v", values)
	}
	for _, label := range labels[1:] {
		if label == "main" {
			t.Fatalf("expected raw default branch to be normalized or skipped, got labels %v", labels)
		}
	}
}
