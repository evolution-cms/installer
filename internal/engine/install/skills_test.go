package install

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestPlanSkillsInstallDryRunWritesNothing(t *testing.T) {
	t.Parallel()

	sourceRoot := makeSkillsSource(t)
	projectRoot := t.TempDir()

	plan, err := planSkillsInstall(Options{
		Skills:       []string{"default"},
		SkillsSource: sourceRoot,
		SkillsDryRun: true,
	}, projectRoot)
	if err != nil {
		t.Fatalf("planSkillsInstall returned error: %v", err)
	}
	if !plan.DryRun {
		t.Fatal("expected dry-run plan")
	}
	if len(plan.Selected) != 1 || plan.Selected[0] != "evo-skill-creator" {
		t.Fatalf("selected = %#v", plan.Selected)
	}
	if _, err := os.Lstat(filepath.Join(projectRoot, "core", "custom", "skills", "evo-skill-creator")); !os.IsNotExist(err) {
		t.Fatalf("dry-run should not create target, stat err=%v", err)
	}
}

func TestApplySkillsInstallCopyWritesSkillAndLockfile(t *testing.T) {
	t.Parallel()

	sourceRoot := makeSkillsSource(t)
	projectRoot := t.TempDir()

	plan, err := planSkillsInstall(Options{
		Skills:       []string{"evo-skill-creator"},
		SkillsSource: sourceRoot,
		SkillsRef:    "main",
	}, projectRoot)
	if err != nil {
		t.Fatalf("planSkillsInstall returned error: %v", err)
	}
	if err := applySkillsInstallPlan(plan); err != nil {
		t.Fatalf("applySkillsInstallPlan returned error: %v", err)
	}

	targetSkill := filepath.Join(projectRoot, "core", "custom", "skills", "evo-skill-creator", "SKILL.md")
	if raw, err := os.ReadFile(targetSkill); err != nil || !strings.Contains(string(raw), "evo-skill-creator") {
		t.Fatalf("expected copied SKILL.md, err=%v raw=%q", err, raw)
	}
	lockPath := filepath.Join(projectRoot, "core", "custom", "skills", ".evo-skills.lock.json")
	raw, err := os.ReadFile(lockPath)
	if err != nil {
		t.Fatalf("expected lockfile: %v", err)
	}
	var state skillsInstallState
	if err := json.Unmarshal(raw, &state); err != nil {
		t.Fatalf("invalid lockfile JSON: %v", err)
	}
	if state.SchemaVersion != skillsStateSchema {
		t.Fatalf("schema version = %q", state.SchemaVersion)
	}
	if state.Mode != "copy" {
		t.Fatalf("mode = %q, want copy", state.Mode)
	}
	if state.Source.Ref != "main" {
		t.Fatalf("source ref = %q, want main", state.Source.Ref)
	}
	if len(state.InstalledSkills) != 1 || state.InstalledSkills[0].Name != "evo-skill-creator" {
		t.Fatalf("installed skills = %#v", state.InstalledSkills)
	}
	if got := state.InstalledSkills[0].FileHashes["agents/openai.yaml"]; got == "" {
		t.Fatalf("expected file hash evidence for agents/openai.yaml, got %#v", state.InstalledSkills[0].FileHashes)
	}
	targetAgent := filepath.Join(projectRoot, "core", "custom", "skills", "evo-skill-creator", "agents", "openai.yaml")
	if raw, err := os.ReadFile(targetAgent); err != nil || !strings.Contains(string(raw), "display_name") {
		t.Fatalf("expected copied agents/openai.yaml, err=%v raw=%q", err, raw)
	}
}

func TestApplySkillsInstallLinkCreatesSymlink(t *testing.T) {
	t.Parallel()

	sourceRoot := makeSkillsSource(t)
	projectRoot := t.TempDir()

	plan, err := planSkillsInstall(Options{
		Skills:       []string{"evo-skill-creator"},
		SkillsSource: sourceRoot,
		SkillsLink:   true,
	}, projectRoot)
	if err != nil {
		t.Fatalf("planSkillsInstall returned error: %v", err)
	}
	if plan.Mode != "link" {
		t.Fatalf("mode = %q, want link", plan.Mode)
	}
	if err := applySkillsInstallPlan(plan); err != nil {
		t.Fatalf("applySkillsInstallPlan returned error: %v", err)
	}

	target := filepath.Join(projectRoot, "core", "custom", "skills", "evo-skill-creator")
	info, err := os.Lstat(target)
	if err != nil {
		t.Fatalf("expected target symlink: %v", err)
	}
	if info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("expected symlink, mode=%s", info.Mode())
	}
}

func TestPlanSkillsInstallMissingSkillFails(t *testing.T) {
	t.Parallel()

	_, err := planSkillsInstall(Options{
		Skills:       []string{"missing-skill"},
		SkillsSource: makeSkillsSource(t),
	}, t.TempDir())
	if err == nil {
		t.Fatal("planSkillsInstall returned nil error for missing skill")
	}
}

func TestPlanSkillsInstallExistingUnmanagedTargetRequiresForce(t *testing.T) {
	t.Parallel()

	projectRoot := t.TempDir()
	target := filepath.Join(projectRoot, "core", "custom", "skills", "evo-skill-creator")
	if err := os.MkdirAll(target, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(target, "SKILL.md"), []byte("unmanaged"), 0o644); err != nil {
		t.Fatal(err)
	}

	_, err := planSkillsInstall(Options{
		Skills:       []string{"evo-skill-creator"},
		SkillsSource: makeSkillsSource(t),
	}, projectRoot)
	if err == nil {
		t.Fatal("planSkillsInstall returned nil error for existing unmanaged target")
	}
}

func TestPlanSkillsInstallDeclaredFileHashMismatchFails(t *testing.T) {
	t.Parallel()

	sourceRoot := makeSkillsSource(t)
	rewriteSkillsManifest(t, sourceRoot, func(manifest *skillsManifest) {
		manifest.Skills[0].FileHashes["agents/openai.yaml"] = "sha256:bad"
	})

	_, err := planSkillsInstall(Options{
		Skills:       []string{"evo-skill-creator"},
		SkillsSource: sourceRoot,
	}, t.TempDir())
	if err == nil || !strings.Contains(err.Error(), "file hash mismatch") {
		t.Fatalf("expected file hash mismatch error, got %v", err)
	}
}

func TestPlanSkillsInstallDeclaredFileMissingFails(t *testing.T) {
	t.Parallel()

	sourceRoot := makeSkillsSource(t)
	if err := os.Remove(filepath.Join(sourceRoot, "skills", "evo-skill-creator", "agents", "openai.yaml")); err != nil {
		t.Fatal(err)
	}

	_, err := planSkillsInstall(Options{
		Skills:       []string{"evo-skill-creator"},
		SkillsSource: sourceRoot,
	}, t.TempDir())
	if err == nil || !strings.Contains(err.Error(), "declared file is not readable") {
		t.Fatalf("expected missing declared file error, got %v", err)
	}
}

func TestPlanSkillsInstallNoneIsNoop(t *testing.T) {
	t.Parallel()

	plan, err := planSkillsInstall(Options{
		Skills: []string{"none"},
	}, t.TempDir())
	if err != nil {
		t.Fatalf("planSkillsInstall returned error: %v", err)
	}
	if len(plan.Selected) != 0 || len(plan.Operations) != 0 {
		t.Fatalf("expected no-op plan, got selected=%#v operations=%#v", plan.Selected, plan.Operations)
	}
}

func makeSkillsSource(t *testing.T) string {
	t.Helper()

	root := t.TempDir()
	skillDir := filepath.Join(root, "skills", "evo-skill-creator")
	if err := os.MkdirAll(filepath.Join(skillDir, "agents"), 0o755); err != nil {
		t.Fatal(err)
	}
	skillBody := "---\nname: evo-skill-creator\ndescription: Test skill.\n---\n\n# evo-skill-creator\n"
	skillPath := filepath.Join(skillDir, "SKILL.md")
	if err := os.WriteFile(skillPath, []byte(skillBody), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(skillDir, "agents", "openai.yaml"), []byte("display_name: Test\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	hash, err := sha256File(skillPath)
	if err != nil {
		t.Fatal(err)
	}
	agentHash, err := sha256File(filepath.Join(skillDir, "agents", "openai.yaml"))
	if err != nil {
		t.Fatal(err)
	}

	manifestDir := filepath.Join(root, "manifests")
	if err := os.MkdirAll(manifestDir, 0o755); err != nil {
		t.Fatal(err)
	}
	manifest := skillsManifest{
		SchemaVersion:  skillsManifestVersion,
		InstallRoot:    "core/custom/skills",
		Lockfile:       "core/custom/skills/.evo-skills.lock.json",
		DefaultInstall: []string{"evo-skill-creator"},
		Skills: []skillsManifestEntry{
			{
				Name:          "evo-skill-creator",
				SourcePath:    "skills/evo-skill-creator",
				SkillFile:     "skills/evo-skill-creator/SKILL.md",
				InstallTarget: "core/custom/skills/evo-skill-creator",
				ContentHash:   hash,
				FileHashes: map[string]string{
					"SKILL.md":           hash,
					"agents/openai.yaml": agentHash,
				},
				ModeSupport: []string{"copy", "link"},
			},
		},
	}
	raw, err := json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(manifestDir, "evo-skills.manifest.json"), append(raw, '\n'), 0o644); err != nil {
		t.Fatal(err)
	}
	return root
}

func rewriteSkillsManifest(t *testing.T, sourceRoot string, mutate func(*skillsManifest)) {
	t.Helper()

	manifestPath := filepath.Join(sourceRoot, "manifests", "evo-skills.manifest.json")
	raw, err := os.ReadFile(manifestPath)
	if err != nil {
		t.Fatal(err)
	}
	var manifest skillsManifest
	if err := json.Unmarshal(raw, &manifest); err != nil {
		t.Fatal(err)
	}
	mutate(&manifest)
	raw, err = json.MarshalIndent(manifest, "", "  ")
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(manifestPath, append(raw, '\n'), 0o644); err != nil {
		t.Fatal(err)
	}
}
