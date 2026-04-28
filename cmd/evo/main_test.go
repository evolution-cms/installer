package main

import "testing"

func TestSplitInstallArgsKeepsPresetFlags(t *testing.T) {
	installDir, flags, err := splitInstallArgs([]string{
		"/tmp/site",
		"--preset=evolution-cms-presets/default@dev",
		"--cli",
	})
	if err != nil {
		t.Fatalf("splitInstallArgs returned error: %v", err)
	}
	if installDir != "/tmp/site" {
		t.Fatalf("installDir = %q, want /tmp/site", installDir)
	}

	want := []string{"--preset=evolution-cms-presets/default@dev", "--cli"}
	if len(flags) != len(want) {
		t.Fatalf("flags = %#v, want %#v", flags, want)
	}
	for i := range want {
		if flags[i] != want[i] {
			t.Fatalf("flags[%d] = %q, want %q", i, flags[i], want[i])
		}
	}
}

func TestSplitInstallArgsRequiresPresetValue(t *testing.T) {
	_, _, err := splitInstallArgs([]string{"/tmp/site", "--preset"})
	if err == nil {
		t.Fatal("splitInstallArgs returned nil error for missing --preset value")
	}
}

func TestSplitInstallArgsAllowsFlagsWithoutInstallDir(t *testing.T) {
	installDir, flags, err := splitInstallArgs([]string{"--branch=3.5.x", "--preset=evolution-cms-presets/default-daisyui"})
	if err != nil {
		t.Fatalf("splitInstallArgs returned error: %v", err)
	}
	if installDir != "" {
		t.Fatalf("installDir = %q, want empty", installDir)
	}

	want := []string{"--branch=3.5.x", "--preset=evolution-cms-presets/default-daisyui"}
	if len(flags) != len(want) {
		t.Fatalf("flags = %#v, want %#v", flags, want)
	}
	for i := range want {
		if flags[i] != want[i] {
			t.Fatalf("flags[%d] = %q, want %q", i, flags[i], want[i])
		}
	}
}

func TestParseExtrasSelectionsKeepsLegacyStoreID(t *testing.T) {
	selections, err := parseExtrasSelections("legacy-store:84@1.12.2,sSeo")
	if err != nil {
		t.Fatalf("parseExtrasSelections returned error: %v", err)
	}
	if len(selections) != 2 {
		t.Fatalf("expected 2 selections, got %#v", selections)
	}
	if selections[0].ID != "legacy-store:84" {
		t.Fatalf("expected legacy ID, got %#v", selections[0])
	}
	if selections[0].Version != "1.12.2" {
		t.Fatalf("expected legacy version, got %#v", selections[0])
	}
	if selections[1].Name != "sSeo" {
		t.Fatalf("expected managed name selection, got %#v", selections[1])
	}
}
