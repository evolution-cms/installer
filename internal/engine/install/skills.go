package install

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
)

const (
	skillsStepID          = "skills"
	skillsManifestPath    = "manifests/evo-skills.manifest.json"
	skillsStateSchema     = "evo.skills.install-state.v1"
	skillsManifestVersion = "evo.skills.manifest.v1"
	skillsWorkflowVersion = "evo.skills.workflow.v1"
)

type skillsManifest struct {
	SchemaVersion  string                `json:"schema_version"`
	InstallRoot    string                `json:"install_root"`
	Lockfile       string                `json:"lockfile"`
	DefaultInstall []string              `json:"default_install"`
	Skills         []skillsManifestEntry `json:"skills"`
}

type skillsManifestEntry struct {
	Name          string            `json:"name"`
	SourcePath    string            `json:"source_path"`
	SkillFile     string            `json:"skill_file"`
	InstallTarget string            `json:"install_target"`
	ContentHash   string            `json:"content_hash"`
	FileHashes    map[string]string `json:"file_hashes,omitempty"`
	WorkflowID    string            `json:"workflow_id,omitempty"`
	WorkflowFile  string            `json:"workflow_file,omitempty"`
	WorkflowHash  string            `json:"workflow_hash,omitempty"`
	ModeSupport   []string          `json:"mode_support"`
}

type skillsInstallPlan struct {
	ProjectRoot     string
	SourceRoot      string
	SourceRef       string
	ManifestPath    string
	InstallRoot     string
	LockfilePath    string
	Mode            string
	DryRun          bool
	Selected        []string
	InstalledSkills []skillsInstalledItem
	Operations      []skillsInstallOperation
}

type skillsInstalledItem struct {
	Name        string                  `json:"name"`
	SourcePath  string                  `json:"source_path"`
	TargetPath  string                  `json:"target_path"`
	ContentHash string                  `json:"content_hash"`
	FileHashes  map[string]string       `json:"file_hashes,omitempty"`
	Workflow    *skillsWorkflowEvidence `json:"workflow,omitempty"`
	Mode        string                  `json:"mode"`
	Status      string                  `json:"status"`
}

type skillsWorkflowDefinition struct {
	SchemaVersion         string                `json:"schema_version"`
	WorkflowID            string                `json:"workflow_id"`
	Name                  string                `json:"name"`
	Version               string                `json:"version"`
	Status                string                `json:"status"`
	Autoload              bool                  `json:"autoload"`
	Autorun               bool                  `json:"autorun"`
	OwnerApprovalRequired bool                  `json:"owner_approval_required"`
	PromotionAllowed      bool                  `json:"promotion_allowed"`
	NoWriteActions        bool                  `json:"no_write_actions"`
	Dependencies          []string              `json:"dependencies"`
	Stages                []skillsWorkflowStage `json:"stages"`
}

type skillsWorkflowStage struct {
	ID          string `json:"id"`
	Order       int    `json:"order"`
	Label       string `json:"label"`
	Purpose     string `json:"purpose,omitempty"`
	WriteAction bool   `json:"write_action"`
	Status      string `json:"status"`
}

type skillsWorkflowEvidence struct {
	WorkflowID             string                        `json:"workflow_id"`
	WorkflowVersion        string                        `json:"workflow_version"`
	WorkflowHash           string                        `json:"workflow_hash"`
	WorkflowFile           string                        `json:"workflow_file"`
	Status                 string                        `json:"status"`
	Autoload               bool                          `json:"autoload"`
	Autorun                bool                          `json:"autorun"`
	OwnerApprovalRequired  bool                          `json:"owner_approval_required"`
	PromotionAllowed       bool                          `json:"promotion_allowed"`
	NoWriteActionsExecuted bool                          `json:"no_write_actions_executed"`
	DryRunResult           string                        `json:"dry_run_result"`
	Dependencies           []string                      `json:"dependencies"`
	ResolvedOrder          []string                      `json:"resolved_order"`
	Stages                 []skillsWorkflowStageEvidence `json:"stages,omitempty"`
}

type skillsWorkflowStageEvidence struct {
	ID          string `json:"id"`
	Label       string `json:"label"`
	Order       int    `json:"order"`
	Status      string `json:"status"`
	WriteAction bool   `json:"write_action"`
}

type skillsInstallOperation struct {
	Kind      string `json:"kind"`
	Source    string `json:"source,omitempty"`
	Target    string `json:"target,omitempty"`
	Ownership string `json:"ownership,omitempty"`
	Status    string `json:"status"`
}

type skillsInstallState struct {
	SchemaVersion   string                   `json:"schema_version"`
	InstalledAt     string                   `json:"installed_at"`
	ProjectRoot     string                   `json:"project_root"`
	SkillsRoot      string                   `json:"skills_root"`
	Mode            string                   `json:"mode"`
	Source          skillsInstallStateSource `json:"source"`
	InstalledSkills []skillsInstalledItem    `json:"installed_skills"`
	Operations      []skillsInstallOperation `json:"operations,omitempty"`
}

type skillsInstallStateSource struct {
	Type     string `json:"type"`
	Path     string `json:"path,omitempty"`
	Ref      string `json:"ref,omitempty"`
	Commit   string `json:"commit,omitempty"`
	Manifest string `json:"manifest,omitempty"`
}

func (e *Engine) maybeRunSkillsInstall(ctx context.Context, emit func(domain.Event) bool, workDir string) {
	if len(e.opt.Skills) == 0 {
		return
	}

	_ = emit(domain.Event{
		Type:     domain.EventStepStart,
		StepID:   skillsStepID,
		Source:   "skills",
		Severity: domain.SeverityInfo,
		Payload: domain.StepStartPayload{
			Label: "Install EVO Skills",
			Index: 8,
			Total: 8,
		},
	})

	if err := ctx.Err(); err != nil {
		_ = emit(domain.Event{
			Type:     domain.EventError,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityError,
			Payload: domain.LogPayload{
				Message: "EVO skills install cancelled.",
				Fields:  map[string]string{"error": err.Error()},
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityError,
			Payload:  domain.StepDonePayload{OK: false},
		})
		return
	}

	plan, err := planSkillsInstall(e.opt, workDir)
	if err != nil {
		_ = emit(domain.Event{
			Type:     domain.EventError,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityError,
			Payload: domain.LogPayload{
				Message: "EVO skills install failed.",
				Fields:  map[string]string{"error": err.Error()},
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityError,
			Payload:  domain.StepDonePayload{OK: false},
		})
		return
	}

	if len(plan.Selected) == 0 {
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "EVO skills install skipped (--skills=none).",
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityInfo,
			Payload:  domain.StepDonePayload{OK: true},
		})
		return
	}

	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   skillsStepID,
		Source:   "skills",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: fmt.Sprintf("Planned EVO skills install: %s (%s mode).", strings.Join(plan.Selected, ", "), plan.Mode),
		},
	})
	for _, item := range plan.InstalledSkills {
		if item.Workflow == nil {
			continue
		}
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   skillsStepID,
			Source:   "skills",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: fmt.Sprintf(
					"Workflow autoload planned for %s: %s (%s).",
					item.Name,
					item.Workflow.WorkflowID,
					strings.Join(item.Workflow.ResolvedOrder, " -> "),
				),
				Fields: map[string]string{
					"autorun":                   "false",
					"no_write_actions_executed": "true",
				},
			},
		})
	}

	if !plan.DryRun {
		if err := applySkillsInstallPlan(plan); err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventError,
				StepID:   skillsStepID,
				Source:   "skills",
				Severity: domain.SeverityError,
				Payload: domain.LogPayload{
					Message: "EVO skills install failed.",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   skillsStepID,
				Source:   "skills",
				Severity: domain.SeverityError,
				Payload:  domain.StepDonePayload{OK: false},
			})
			return
		}
	}

	msg := "EVO skills dry-run completed; no files written."
	if !plan.DryRun {
		msg = "EVO skills installed and lockfile written."
	}
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   skillsStepID,
		Source:   "skills",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: msg,
		},
	})
	_ = emit(domain.Event{
		Type:     domain.EventStepDone,
		StepID:   skillsStepID,
		Source:   "skills",
		Severity: domain.SeverityInfo,
		Payload:  domain.StepDonePayload{OK: true},
	})
}

func planSkillsInstall(opt Options, workDir string) (skillsInstallPlan, error) {
	mode := "copy"
	if opt.SkillsLink {
		mode = "link"
	}

	projectRoot := absDir(workDir)
	sourceRoot := absDir(strings.TrimSpace(opt.SkillsSource))
	if len(opt.Skills) == 0 || isSkillsNone(opt.Skills) {
		return skillsInstallPlan{
			ProjectRoot: projectRoot,
			Mode:        mode,
			DryRun:      opt.SkillsDryRun,
		}, nil
	}
	if sourceRoot == "" {
		return skillsInstallPlan{}, errors.New("--skills-source is required for CLI skills install MVP")
	}
	manifestPath := filepath.Join(sourceRoot, skillsManifestPath)
	manifest, err := readSkillsManifest(manifestPath)
	if err != nil {
		return skillsInstallPlan{}, err
	}
	if strings.TrimSpace(manifest.InstallRoot) == "" {
		manifest.InstallRoot = "core/custom/skills"
	}
	if strings.TrimSpace(manifest.Lockfile) == "" {
		manifest.Lockfile = filepath.Join(manifest.InstallRoot, ".evo-skills.lock.json")
	}

	selected, err := resolveSkillsSelection(opt.Skills, manifest)
	if err != nil {
		return skillsInstallPlan{}, err
	}
	if len(selected) == 0 {
		return skillsInstallPlan{
			ProjectRoot:  projectRoot,
			SourceRoot:   sourceRoot,
			ManifestPath: manifestPath,
			Mode:         mode,
			DryRun:       opt.SkillsDryRun,
		}, nil
	}

	installRoot := filepath.Join(projectRoot, filepath.FromSlash(manifest.InstallRoot))
	lockfilePath := filepath.Join(projectRoot, filepath.FromSlash(manifest.Lockfile))
	previousState, _ := readSkillsInstallState(lockfilePath)

	byName := map[string]skillsManifestEntry{}
	for _, item := range manifest.Skills {
		byName[item.Name] = item
	}

	plan := skillsInstallPlan{
		ProjectRoot:  projectRoot,
		SourceRoot:   sourceRoot,
		SourceRef:    strings.TrimSpace(opt.SkillsRef),
		ManifestPath: manifestPath,
		InstallRoot:  installRoot,
		LockfilePath: lockfilePath,
		Mode:         mode,
		DryRun:       opt.SkillsDryRun,
		Selected:     selected,
		Operations: []skillsInstallOperation{
			{Kind: "mkdir", Target: filepath.ToSlash(manifest.InstallRoot), Ownership: "managed", Status: "planned"},
		},
	}

	for _, name := range selected {
		item, ok := byName[name]
		if !ok {
			return skillsInstallPlan{}, fmt.Errorf("selected skill %q is missing from manifest", name)
		}
		if !supportsSkillsMode(item.ModeSupport, mode) {
			return skillsInstallPlan{}, fmt.Errorf("skill %q does not support %s mode", name, mode)
		}
		sourceDir := filepath.Join(sourceRoot, filepath.FromSlash(item.SourcePath))
		sourceFile := filepath.Join(sourceRoot, filepath.FromSlash(item.SkillFile))
		if strings.TrimSpace(item.InstallTarget) == "" {
			item.InstallTarget = filepath.ToSlash(filepath.Join(manifest.InstallRoot, item.Name))
		}
		targetDir := filepath.Join(projectRoot, filepath.FromSlash(item.InstallTarget))

		if st, err := os.Stat(sourceDir); err != nil || !st.IsDir() {
			return skillsInstallPlan{}, fmt.Errorf("skill %q source directory is not readable: %s", name, sourceDir)
		}
		if st, err := os.Stat(sourceFile); err != nil || st.IsDir() {
			return skillsInstallPlan{}, fmt.Errorf("skill %q SKILL.md is not readable: %s", name, sourceFile)
		}
		hash, err := sha256File(sourceFile)
		if err != nil {
			return skillsInstallPlan{}, fmt.Errorf("unable to hash skill %q: %w", name, err)
		}
		if expected := strings.TrimSpace(item.ContentHash); expected != "" && expected != hash {
			return skillsInstallPlan{}, fmt.Errorf("skill %q hash mismatch: manifest %s, actual %s", name, expected, hash)
		}
		fileHashes, err := verifySkillFileHashes(sourceDir, item)
		if err != nil {
			return skillsInstallPlan{}, err
		}
		workflowEvidence, err := resolveSkillWorkflow(sourceRoot, item)
		if err != nil {
			return skillsInstallPlan{}, err
		}
		if workflowEvidence != nil {
			plan.Operations = append(plan.Operations, skillsInstallOperation{
				Kind:      "autoload-workflow",
				Source:    filepath.ToSlash(item.WorkflowFile),
				Target:    workflowEvidence.WorkflowID,
				Ownership: "managed",
				Status:    "planned",
			})
		}

		ownership := "managed"
		if _, err := os.Lstat(targetDir); err == nil {
			if mode == "link" && symlinkPointsTo(targetDir, sourceDir) {
				plan.Operations = append(plan.Operations, skillsInstallOperation{
					Kind:      "skip",
					Source:    filepath.ToSlash(sourceDir),
					Target:    filepath.ToSlash(item.InstallTarget),
					Ownership: "managed",
					Status:    "planned",
				})
				plan.InstalledSkills = append(plan.InstalledSkills, skillsInstalledItem{
					Name:        name,
					SourcePath:  filepath.ToSlash(item.SourcePath),
					TargetPath:  filepath.ToSlash(item.InstallTarget),
					ContentHash: hash,
					FileHashes:  fileHashes,
					Workflow:    workflowEvidence,
					Mode:        mode,
					Status:      "installed",
				})
				continue
			}
			managed := isManagedSkillTarget(previousState, name, item.InstallTarget)
			if !managed {
				ownership = "unmanaged"
				if !opt.Force {
					return skillsInstallPlan{}, fmt.Errorf("target already exists for skill %q (%s); use --force to replace unmanaged files", name, targetDir)
				}
			}
		} else if !errors.Is(err, os.ErrNotExist) {
			return skillsInstallPlan{}, fmt.Errorf("unable to inspect target for skill %q: %w", name, err)
		}

		plan.Operations = append(plan.Operations, skillsInstallOperation{
			Kind:      mode,
			Source:    filepath.ToSlash(sourceDir),
			Target:    filepath.ToSlash(item.InstallTarget),
			Ownership: ownership,
			Status:    "planned",
		})
		plan.InstalledSkills = append(plan.InstalledSkills, skillsInstalledItem{
			Name:        name,
			SourcePath:  filepath.ToSlash(item.SourcePath),
			TargetPath:  filepath.ToSlash(item.InstallTarget),
			ContentHash: hash,
			FileHashes:  fileHashes,
			Workflow:    workflowEvidence,
			Mode:        mode,
			Status:      "installed",
		})
	}
	plan.Operations = append(plan.Operations, skillsInstallOperation{
		Kind:      "write-lockfile",
		Target:    filepath.ToSlash(manifest.Lockfile),
		Ownership: "managed",
		Status:    "planned",
	})
	return plan, nil
}

func applySkillsInstallPlan(plan skillsInstallPlan) error {
	if len(plan.Selected) == 0 {
		return nil
	}
	if err := os.MkdirAll(plan.InstallRoot, 0o755); err != nil {
		return err
	}
	for _, op := range plan.Operations {
		switch op.Kind {
		case "copy":
			source := filepath.FromSlash(op.Source)
			target := filepath.Join(plan.ProjectRoot, filepath.FromSlash(op.Target))
			if err := replaceTarget(target); err != nil {
				return err
			}
			if err := copyDir(source, target); err != nil {
				return err
			}
		case "link":
			source := filepath.FromSlash(op.Source)
			target := filepath.Join(plan.ProjectRoot, filepath.FromSlash(op.Target))
			if err := replaceTarget(target); err != nil {
				return err
			}
			if err := os.Symlink(source, target); err != nil {
				return err
			}
		}
	}
	state := skillsInstallState{
		SchemaVersion: skillsStateSchema,
		InstalledAt:   time.Now().UTC().Format(time.RFC3339),
		ProjectRoot:   plan.ProjectRoot,
		SkillsRoot:    filepath.ToSlash(relOrSelf(plan.ProjectRoot, plan.InstallRoot)),
		Mode:          plan.Mode,
		Source: skillsInstallStateSource{
			Type:     "local-path",
			Path:     plan.SourceRoot,
			Ref:      plan.SourceRef,
			Manifest: plan.ManifestPath,
		},
		InstalledSkills: plan.InstalledSkills,
		Operations:      appliedSkillsOperations(plan.Operations),
	}
	raw, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(plan.LockfilePath), 0o755); err != nil {
		return err
	}
	return os.WriteFile(plan.LockfilePath, append(raw, '\n'), 0o644)
}

func readSkillsManifest(path string) (skillsManifest, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return skillsManifest{}, fmt.Errorf("unable to read skills manifest: %w", err)
	}
	var manifest skillsManifest
	if err := json.Unmarshal(raw, &manifest); err != nil {
		return skillsManifest{}, fmt.Errorf("invalid skills manifest: %w", err)
	}
	if strings.TrimSpace(manifest.SchemaVersion) != skillsManifestVersion {
		return skillsManifest{}, fmt.Errorf("unsupported skills manifest version %q", manifest.SchemaVersion)
	}
	return manifest, nil
}

func readSkillsInstallState(path string) (skillsInstallState, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return skillsInstallState{}, err
	}
	var state skillsInstallState
	if err := json.Unmarshal(raw, &state); err != nil {
		return skillsInstallState{}, err
	}
	return state, nil
}

func resolveSkillsSelection(raw []string, manifest skillsManifest) ([]string, error) {
	if len(raw) == 0 || isSkillsNone(raw) {
		return nil, nil
	}
	if len(raw) == 1 && strings.EqualFold(strings.TrimSpace(raw[0]), "default") {
		return uniqueSkillNames(manifest.DefaultInstall), nil
	}
	selected := uniqueSkillNames(raw)
	for _, name := range selected {
		if strings.EqualFold(name, "default") || strings.EqualFold(name, "none") {
			return nil, fmt.Errorf("--skills value %q cannot be mixed with explicit skills", name)
		}
	}
	return selected, nil
}

func uniqueSkillNames(raw []string) []string {
	out := make([]string, 0, len(raw))
	seen := map[string]struct{}{}
	for _, item := range raw {
		item = strings.TrimSpace(item)
		if item == "" {
			continue
		}
		key := strings.ToLower(item)
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}
		out = append(out, item)
	}
	return out
}

func isSkillsNone(raw []string) bool {
	return len(raw) == 1 && strings.EqualFold(strings.TrimSpace(raw[0]), "none")
}

func supportsSkillsMode(modes []string, mode string) bool {
	if len(modes) == 0 {
		return mode == "copy"
	}
	for _, item := range modes {
		if strings.EqualFold(strings.TrimSpace(item), mode) {
			return true
		}
	}
	return false
}

func sha256File(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return "sha256:" + hex.EncodeToString(h.Sum(nil)), nil
}

func verifySkillFileHashes(sourceDir string, item skillsManifestEntry) (map[string]string, error) {
	if len(item.FileHashes) == 0 {
		return nil, nil
	}
	out := make(map[string]string, len(item.FileHashes))
	for relativePath, expectedHash := range item.FileHashes {
		relativePath = strings.TrimSpace(relativePath)
		if relativePath == "" {
			return nil, fmt.Errorf("skill %q declares an empty file_hashes path", item.Name)
		}
		cleanPath := filepath.Clean(filepath.FromSlash(relativePath))
		if filepath.IsAbs(cleanPath) || cleanPath == ".." || strings.HasPrefix(cleanPath, ".."+string(filepath.Separator)) {
			return nil, fmt.Errorf("skill %q declares unsafe file_hashes path %q", item.Name, relativePath)
		}
		filePath := filepath.Join(sourceDir, cleanPath)
		stat, err := os.Stat(filePath)
		if err != nil || stat.IsDir() {
			return nil, fmt.Errorf("skill %q declared file is not readable: %s", item.Name, filepath.ToSlash(cleanPath))
		}
		actualHash, err := sha256File(filePath)
		if err != nil {
			return nil, fmt.Errorf("unable to hash skill %q declared file %q: %w", item.Name, filepath.ToSlash(cleanPath), err)
		}
		if expected := strings.TrimSpace(expectedHash); expected != "" && expected != actualHash {
			return nil, fmt.Errorf("skill %q file hash mismatch for %q: manifest %s, actual %s", item.Name, filepath.ToSlash(cleanPath), expected, actualHash)
		}
		out[filepath.ToSlash(cleanPath)] = actualHash
	}
	return out, nil
}

func resolveSkillWorkflow(sourceRoot string, item skillsManifestEntry) (*skillsWorkflowEvidence, error) {
	if strings.TrimSpace(item.WorkflowID) == "" && strings.TrimSpace(item.WorkflowFile) == "" && strings.TrimSpace(item.WorkflowHash) == "" {
		return nil, nil
	}
	if strings.TrimSpace(item.WorkflowID) == "" || strings.TrimSpace(item.WorkflowFile) == "" || strings.TrimSpace(item.WorkflowHash) == "" {
		return nil, fmt.Errorf("skill %q workflow_id, workflow_file, and workflow_hash must be declared together", item.Name)
	}
	cleanPath := filepath.Clean(filepath.FromSlash(item.WorkflowFile))
	if filepath.IsAbs(cleanPath) || cleanPath == ".." || strings.HasPrefix(cleanPath, ".."+string(filepath.Separator)) {
		return nil, fmt.Errorf("skill %q declares unsafe workflow_file %q", item.Name, item.WorkflowFile)
	}
	workflowPath := filepath.Join(sourceRoot, cleanPath)
	stat, err := os.Stat(workflowPath)
	if err != nil || stat.IsDir() {
		return nil, fmt.Errorf("skill %q workflow_file is not readable: %s", item.Name, filepath.ToSlash(cleanPath))
	}
	actualHash, err := sha256File(workflowPath)
	if err != nil {
		return nil, fmt.Errorf("unable to hash skill %q workflow_file %q: %w", item.Name, filepath.ToSlash(cleanPath), err)
	}
	if expected := strings.TrimSpace(item.WorkflowHash); expected != "" && expected != actualHash {
		return nil, fmt.Errorf("skill %q workflow hash mismatch: manifest %s, actual %s", item.Name, expected, actualHash)
	}
	raw, err := os.ReadFile(workflowPath)
	if err != nil {
		return nil, fmt.Errorf("unable to read skill %q workflow_file %q: %w", item.Name, filepath.ToSlash(cleanPath), err)
	}
	var workflow skillsWorkflowDefinition
	if err := json.Unmarshal(raw, &workflow); err != nil {
		return nil, fmt.Errorf("invalid skill %q workflow JSON: %w", item.Name, err)
	}
	return workflowEvidence(item, filepath.ToSlash(cleanPath), actualHash, workflow)
}

func workflowEvidence(item skillsManifestEntry, workflowFile string, workflowHash string, workflow skillsWorkflowDefinition) (*skillsWorkflowEvidence, error) {
	if strings.TrimSpace(workflow.SchemaVersion) != skillsWorkflowVersion {
		return nil, fmt.Errorf("skill %q workflow has unsupported schema version %q", item.Name, workflow.SchemaVersion)
	}
	if workflow.WorkflowID != item.WorkflowID {
		return nil, fmt.Errorf("skill %q workflow_id mismatch: manifest %q, workflow %q", item.Name, item.WorkflowID, workflow.WorkflowID)
	}
	if strings.TrimSpace(workflow.Version) == "" {
		return nil, fmt.Errorf("skill %q workflow version is required", item.Name)
	}
	if !workflow.Autoload {
		return nil, fmt.Errorf("skill %q workflow autoload must be true", item.Name)
	}
	if workflow.Autorun {
		return nil, fmt.Errorf("skill %q workflow autorun must be false", item.Name)
	}
	if !workflow.OwnerApprovalRequired {
		return nil, fmt.Errorf("skill %q workflow owner_approval_required must be true", item.Name)
	}
	if workflow.PromotionAllowed {
		return nil, fmt.Errorf("skill %q workflow promotion_allowed must be false in CLI proof", item.Name)
	}
	if !workflow.NoWriteActions {
		return nil, fmt.Errorf("skill %q workflow no_write_actions must be true", item.Name)
	}
	if len(workflow.Stages) == 0 {
		return nil, fmt.Errorf("skill %q workflow stages are required", item.Name)
	}

	seen := map[string]struct{}{}
	lastOrder := 0
	resolvedOrder := make([]string, 0, len(workflow.Stages))
	stages := make([]skillsWorkflowStageEvidence, 0, len(workflow.Stages))
	for _, stage := range workflow.Stages {
		if strings.TrimSpace(stage.ID) == "" {
			return nil, fmt.Errorf("skill %q workflow stage id is required", item.Name)
		}
		if _, ok := seen[stage.ID]; ok {
			return nil, fmt.Errorf("skill %q workflow stage %q is duplicated", item.Name, stage.ID)
		}
		seen[stage.ID] = struct{}{}
		if stage.Order <= lastOrder {
			return nil, fmt.Errorf("skill %q workflow stage %q order is not strictly increasing", item.Name, stage.ID)
		}
		lastOrder = stage.Order
		if strings.TrimSpace(stage.Label) == "" {
			return nil, fmt.Errorf("skill %q workflow stage %q label is required", item.Name, stage.ID)
		}
		if stage.WriteAction {
			return nil, fmt.Errorf("skill %q workflow stage %q declares a write action; autoload proof forbids it", item.Name, stage.ID)
		}
		status := strings.TrimSpace(stage.Status)
		if status == "" {
			status = "visible"
		}
		resolvedOrder = append(resolvedOrder, stage.Label)
		stages = append(stages, skillsWorkflowStageEvidence{
			ID:          stage.ID,
			Label:       stage.Label,
			Order:       stage.Order,
			Status:      status,
			WriteAction: false,
		})
	}

	dependencies := workflow.Dependencies
	if dependencies == nil {
		dependencies = []string{}
	}
	return &skillsWorkflowEvidence{
		WorkflowID:             workflow.WorkflowID,
		WorkflowVersion:        workflow.Version,
		WorkflowHash:           workflowHash,
		WorkflowFile:           workflowFile,
		Status:                 "available",
		Autoload:               true,
		Autorun:                false,
		OwnerApprovalRequired:  true,
		PromotionAllowed:       false,
		NoWriteActionsExecuted: true,
		DryRunResult:           "workflow_plan_only_no_actions",
		Dependencies:           dependencies,
		ResolvedOrder:          resolvedOrder,
		Stages:                 stages,
	}, nil
}

func isManagedSkillTarget(state skillsInstallState, name string, target string) bool {
	for _, item := range state.InstalledSkills {
		if item.Name == name || filepath.ToSlash(item.TargetPath) == filepath.ToSlash(target) {
			return true
		}
	}
	return false
}

func symlinkPointsTo(target string, source string) bool {
	dest, err := os.Readlink(target)
	if err != nil {
		return false
	}
	if !filepath.IsAbs(dest) {
		dest = filepath.Join(filepath.Dir(target), dest)
	}
	return absDir(dest) == absDir(source)
}

func replaceTarget(path string) error {
	if _, err := os.Lstat(path); err == nil {
		return os.RemoveAll(path)
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}

func copyDir(source string, target string) error {
	return filepath.WalkDir(source, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(source, path)
		if err != nil {
			return err
		}
		if rel == "." {
			return os.MkdirAll(target, 0o755)
		}
		if shouldSkipSkillCopyEntry(rel, d) {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		dest := filepath.Join(target, rel)
		if d.IsDir() {
			return os.MkdirAll(dest, 0o755)
		}
		info, err := d.Info()
		if err != nil {
			return err
		}
		if !info.Mode().IsRegular() {
			return nil
		}
		if err := os.MkdirAll(filepath.Dir(dest), 0o755); err != nil {
			return err
		}
		return copyFile(path, dest, info.Mode().Perm())
	})
}

func shouldSkipSkillCopyEntry(rel string, d os.DirEntry) bool {
	name := d.Name()
	if name == ".git" || name == "node_modules" || name == "vendor" || name == "dist" || name == "build" {
		return true
	}
	_ = rel
	return false
}

func copyFile(source string, target string, perm os.FileMode) error {
	in, err := os.Open(source)
	if err != nil {
		return err
	}
	defer in.Close()
	out, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, perm)
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		_ = out.Close()
		return err
	}
	return out.Close()
}

func appliedSkillsOperations(ops []skillsInstallOperation) []skillsInstallOperation {
	out := make([]skillsInstallOperation, 0, len(ops))
	for _, op := range ops {
		op.Status = "applied"
		out = append(out, op)
	}
	return out
}

func relOrSelf(base string, path string) string {
	rel, err := filepath.Rel(base, path)
	if err != nil || strings.HasPrefix(rel, "..") {
		return path
	}
	return rel
}
