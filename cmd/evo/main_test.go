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

func TestSplitInstallArgsKeepsSkillsFlags(t *testing.T) {
	installDir, flags, err := splitInstallArgs([]string{
		"/tmp/site",
		"--cli",
		"--skills=evo-skill-creator",
		"--skills-source",
		"/tmp/evo-skills",
		"--skills-ref=main",
		"--skills-dry-run",
	})
	if err != nil {
		t.Fatalf("splitInstallArgs returned error: %v", err)
	}
	if installDir != "/tmp/site" {
		t.Fatalf("installDir = %q, want /tmp/site", installDir)
	}
	want := []string{
		"--cli",
		"--skills=evo-skill-creator",
		"--skills-source",
		"/tmp/evo-skills",
		"--skills-ref=main",
		"--skills-dry-run",
	}
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

func TestParseSkillSelections(t *testing.T) {
	selections, err := parseSkillSelections("default,evo-skill-creator,evo-skill-creator")
	if err != nil {
		t.Fatalf("parseSkillSelections returned error: %v", err)
	}
	want := []string{"default", "evo-skill-creator"}
	if len(selections) != len(want) {
		t.Fatalf("selections = %#v, want %#v", selections, want)
	}
	for i := range want {
		if selections[i] != want[i] {
			t.Fatalf("selections[%d] = %q, want %q", i, selections[i], want[i])
		}
	}
}

func TestParseSkillSelectionsRejectsPaths(t *testing.T) {
	if _, err := parseSkillSelections("../bad"); err == nil {
		t.Fatal("parseSkillSelections returned nil error for path-like skill")
	}
}

func TestValidateSkillsRequiresCLI(t *testing.T) {
	if err := validateSkillsCLIOptions("evo-skill-creator", false, false, "", ""); err == nil {
		t.Fatal("validateSkillsCLIOptions returned nil error without --cli")
	}
}

func TestValidateSkillsLinkRequiresSource(t *testing.T) {
	if err := validateSkillsCLIOptions("evo-skill-creator", true, true, "", ""); err == nil {
		t.Fatal("validateSkillsCLIOptions returned nil error for --skills-link without source")
	}
}

func TestValidateSkillsLinkConflictsWithRef(t *testing.T) {
	if err := validateSkillsCLIOptions("evo-skill-creator", true, true, "/tmp/evo-skills", "main"); err == nil {
		t.Fatal("validateSkillsCLIOptions returned nil error for link/ref conflict")
	}
}
