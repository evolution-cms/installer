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
