package ui

import "testing"

func TestHeaderTitleIncludesInstallerVersion(t *testing.T) {
	m := &Model{meta: Meta{Version: "1.2.3"}}

	if got, want := m.headerTitle(), "Evolution CMS Installer v1.2.3"; got != want {
		t.Fatalf("headerTitle()=%q, want %q", got, want)
	}
}

func TestHeaderTitleFallsBackToDevVersion(t *testing.T) {
	m := &Model{}

	if got, want := m.headerTitle(), "Evolution CMS Installer dev"; got != want {
		t.Fatalf("headerTitle()=%q, want %q", got, want)
	}
}
