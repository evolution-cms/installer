package install

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/evolution-cms/installer/internal/domain"
)

type presetExtrasManifest struct {
	RequiredExtras []presetExtraRef `json:"requiredExtras"`
	Extras         struct {
		Required []presetExtraRef `json:"required"`
	} `json:"extras"`
}

type presetComposerManifest struct {
	Require map[string]string `json:"require"`
}

type presetExtraRef struct {
	ID           string
	Name         string
	Source       string
	Version      string
	ComposerName string
}

func (r *presetExtraRef) UnmarshalJSON(raw []byte) error {
	var value string
	if err := json.Unmarshal(raw, &value); err == nil {
		*r = presetExtraRefFromString(value)
		return nil
	}

	var object struct {
		ID      string `json:"id"`
		Name    string `json:"name"`
		Package string `json:"package"`
		Source  string `json:"source"`
		Version string `json:"version"`
	}
	if err := json.Unmarshal(raw, &object); err != nil {
		return err
	}

	name := strings.TrimSpace(object.Name)
	if name == "" {
		name = strings.TrimSpace(object.Package)
	}

	*r = presetExtraRef{
		ID:      strings.TrimSpace(object.ID),
		Name:    name,
		Source:  strings.TrimSpace(object.Source),
		Version: strings.TrimSpace(object.Version),
	}
	return nil
}

func presetExtraRefFromString(value string) presetExtraRef {
	name, version := splitSelectionValue(value)
	ref := presetExtraRef{Version: version}
	if strings.Contains(name, ":") {
		ref.ID = name
	} else if strings.Contains(name, "/") {
		ref.Name = name
		ref.Source = "composer-require"
		ref.ComposerName = normalizeComposerPackageName(name)
	} else {
		ref.Name = name
	}
	return ref
}

func loadPresetRequiredExtras(workDir string) ([]domain.ExtrasSelection, []string) {
	var out []domain.ExtrasSelection
	var warnings []string

	composerSelections, composerWarnings := loadComposerRequiredExtras(workDir)
	warnings = append(warnings, composerWarnings...)
	out = mergeRequiredExtras(out, composerSelections)

	for _, path := range presetManifestPaths(workDir) {
		raw, err := os.ReadFile(path)
		if err != nil {
			if os.IsNotExist(err) {
				continue
			}
			warnings = append(warnings, "Unable to read preset extras manifest: "+err.Error())
			continue
		}

		selections, err := parsePresetRequiredExtras(raw)
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("Invalid preset extras manifest %s: %v", filepath.ToSlash(path), err))
			continue
		}
		out = mergeRequiredExtras(out, selections)
	}
	return out, warnings
}

func presetManifestPaths(workDir string) []string {
	root := absDir(workDir)
	return []string{
		filepath.Join(root, "core", "custom", "preset.json"),
		filepath.Join(root, "core", "custom", "evo-preset.json"),
		filepath.Join(root, "core", "custom", "config", "evo-preset.json"),
	}
}

func parsePresetRequiredExtras(raw []byte) ([]domain.ExtrasSelection, error) {
	var manifest presetExtrasManifest
	if err := json.Unmarshal(raw, &manifest); err != nil {
		return nil, err
	}

	refs := append([]presetExtraRef{}, manifest.RequiredExtras...)
	refs = append(refs, manifest.Extras.Required...)

	out := make([]domain.ExtrasSelection, 0, len(refs))
	seen := map[string]struct{}{}
	for _, ref := range refs {
		sel := domain.ExtrasSelection{
			ID:           strings.TrimSpace(ref.ID),
			Name:         strings.TrimSpace(ref.Name),
			Source:       strings.TrimSpace(ref.Source),
			Version:      strings.TrimSpace(ref.Version),
			ComposerName: normalizeComposerPackageName(ref.ComposerName),
			Required:     true,
		}
		if sel.ComposerName == "" && strings.Contains(sel.Name, "/") {
			sel.ComposerName = normalizeComposerPackageName(sel.Name)
		}
		key := extrasSelectionIdentity(sel)
		if key == "" {
			continue
		}
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}
		out = append(out, sel)
	}
	return out, nil
}

func loadComposerRequiredExtras(workDir string) ([]domain.ExtrasSelection, []string) {
	path := filepath.Join(absDir(workDir), "core", "custom", "composer.json")
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, []string{"Unable to read preset composer requirements: " + err.Error()}
	}

	selections, err := parseComposerRequiredExtras(raw)
	if err != nil {
		return nil, []string{fmt.Sprintf("Invalid preset composer requirements %s: %v", filepath.ToSlash(path), err)}
	}
	return selections, nil
}

func parseComposerRequiredExtras(raw []byte) ([]domain.ExtrasSelection, error) {
	var manifest presetComposerManifest
	if err := json.Unmarshal(raw, &manifest); err != nil {
		return nil, err
	}

	out := make([]domain.ExtrasSelection, 0, len(manifest.Require))
	seen := map[string]struct{}{}
	for packageName, version := range manifest.Require {
		composerName := normalizeComposerPackageName(packageName)
		if composerName == "" {
			continue
		}
		sel := domain.ExtrasSelection{
			Name:         composerName,
			Source:       "composer-require",
			Version:      strings.TrimSpace(version),
			ComposerName: composerName,
			Required:     true,
		}
		key := extrasSelectionIdentity(sel)
		if key == "" {
			continue
		}
		if _, ok := seen[key]; ok {
			continue
		}
		seen[key] = struct{}{}
		out = append(out, sel)
	}
	return out, nil
}
