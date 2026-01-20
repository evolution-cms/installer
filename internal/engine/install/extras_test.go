package install

import "testing"

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
