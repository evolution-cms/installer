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
