package domain

import "strings"

type ExtrasStage string

const (
	ExtrasStageSelect   ExtrasStage = "select"
	ExtrasStageProgress ExtrasStage = "progress"
	ExtrasStageSummary  ExtrasStage = "summary"
)

type ExtrasItemStatus string

const (
	ExtrasStatusPending ExtrasItemStatus = "pending"
	ExtrasStatusRunning ExtrasItemStatus = "running"
	ExtrasStatusSuccess ExtrasItemStatus = "success"
	ExtrasStatusError   ExtrasItemStatus = "error"
)

type ExtrasPackage struct {
	ID                 string   `json:"id,omitempty"`
	Name               string   `json:"name"`
	Version            string   `json:"version"`
	Versions           []string `json:"versions,omitempty"`
	Description        string   `json:"description"`
	DefaultInstallMode string   `json:"defaultInstallMode"`
	DefaultBranch      string   `json:"defaultBranch,omitempty"`
	Source             string   `json:"source,omitempty"`
	Section            string   `json:"section,omitempty"`
	Kind               string   `json:"kind,omitempty"`
	InstallMode        string   `json:"installMode,omitempty"`
	Preselected        bool     `json:"preselected,omitempty"`
	Path               string   `json:"path,omitempty"`
	Properties         string   `json:"properties,omitempty"`
	Events             string   `json:"events,omitempty"`
	GUID               string   `json:"guid,omitempty"`
	Category           string   `json:"category,omitempty"`
	LegacyNames        string   `json:"legacyNames,omitempty"`
	Disabled           bool     `json:"disabled,omitempty"`
	ShareParams        int      `json:"shareParams,omitempty"`
	Icon               string   `json:"icon,omitempty"`
	DownloadURL        string   `json:"downloadUrl,omitempty"`
	Dependencies       string   `json:"dependencies,omitempty"`
	Deprecated         bool     `json:"deprecated,omitempty"`
	Method             string   `json:"method,omitempty"`
}

type ExtrasSelection struct {
	ID      string
	Name    string
	Source  string
	Version string
}

type ExtrasItemResult struct {
	Name    string
	Status  ExtrasItemStatus
	Message string
}

type ExtrasItemDetail struct {
	Name   string
	Output string
}

type ExtrasState struct {
	Active       bool
	Stage        ExtrasStage
	ProjectPath  string
	Packages     []ExtrasPackage
	Selections   []ExtrasSelection
	Results      []ExtrasItemResult
	Current      string
	CurrentIndex int
	Total        int
	Details      []ExtrasItemDetail
}

const ExtrasFloatingVersionConstraint = "*"

func IsManagedExtrasPackage(pkg ExtrasPackage) bool {
	source := strings.ToLower(strings.TrimSpace(pkg.Source))
	mode := strings.ToLower(strings.TrimSpace(pkg.InstallMode))
	return source == "managed" || mode == "managed-artisan"
}

func DefaultExtrasInstallVersion(pkg ExtrasPackage) string {
	if IsManagedExtrasPackage(pkg) {
		if HasStableExtrasRelease(pkg) {
			return ExtrasFloatingVersionConstraint
		}
		if branch := ComposerDevConstraint(pkg.DefaultBranch); branch != "" {
			return branch
		}
		if branch := branchConstraintFromVersion(pkg.Version); branch != "" {
			return branch
		}
		for _, v := range pkg.Versions {
			if branch := branchConstraintFromVersion(v); branch != "" {
				return branch
			}
		}
		return ExtrasFloatingVersionConstraint
	}
	return DefaultExtrasVersion(pkg)
}

func branchConstraintFromVersion(version string) string {
	version = strings.TrimSpace(version)
	if version == "" || isStableExtrasVersion(version) {
		return ""
	}
	if isBranchLikeVersion(version) || isVersionLikeBranch(version) {
		return ComposerDevConstraint(version)
	}
	return ""
}

func DefaultExtrasVersion(pkg ExtrasPackage) string {
	mode := strings.ToLower(strings.TrimSpace(pkg.DefaultInstallMode))
	version := strings.TrimSpace(pkg.Version)
	branch := strings.TrimSpace(pkg.DefaultBranch)
	if mode == "latest-release" && version != "" {
		return version
	}
	if mode == "default-branch" && branch != "" {
		return branch
	}
	if version != "" {
		return version
	}
	if branch != "" {
		return branch
	}
	for _, v := range pkg.Versions {
		v = strings.TrimSpace(v)
		if v != "" {
			return v
		}
	}
	return ""
}

func NormalizeExtrasInstallVersion(pkg ExtrasPackage, version string) string {
	version = strings.TrimSpace(version)
	if version == "" {
		return DefaultExtrasInstallVersion(pkg)
	}
	if !IsManagedExtrasPackage(pkg) {
		return version
	}
	branch := strings.TrimSpace(pkg.DefaultBranch)
	if branch != "" && version == branch {
		return ComposerDevConstraint(version)
	}
	if !HasStableExtrasRelease(pkg) && !strings.Contains(version, "/") && isBranchLikeVersion(version) {
		if dev := ComposerDevConstraint(version); dev != "" {
			return dev
		}
	}
	return version
}

func HasStableExtrasRelease(pkg ExtrasPackage) bool {
	if isStableExtrasVersion(pkg.Version) {
		return true
	}
	for _, v := range pkg.Versions {
		if isStableExtrasVersion(v) {
			return true
		}
	}
	return false
}

func ComposerDevConstraint(branch string) string {
	branch = strings.TrimSpace(branch)
	if branch == "" {
		return ""
	}
	lower := strings.ToLower(branch)
	if strings.HasPrefix(lower, "dev-") || strings.HasSuffix(lower, "-dev") {
		return branch
	}
	if isVersionLikeBranch(branch) {
		return branch + "-dev"
	}
	return "dev-" + branch
}

func isStableExtrasVersion(version string) bool {
	version = strings.TrimSpace(version)
	if version == "" {
		return false
	}
	lower := strings.ToLower(version)
	if strings.HasPrefix(lower, "dev-") || strings.HasSuffix(lower, "-dev") {
		return false
	}
	if isBranchLikeVersion(version) {
		return false
	}
	trimmed := strings.TrimPrefix(lower, "v")
	if trimmed == "" {
		return false
	}
	for _, r := range trimmed {
		if (r >= '0' && r <= '9') || r == '.' || r == '-' || r == '+' {
			continue
		}
		return false
	}
	return strings.ContainsAny(trimmed, "0123456789")
}

func isBranchLikeVersion(version string) bool {
	switch strings.ToLower(strings.TrimSpace(version)) {
	case "main", "master", "develop", "development", "dev", "trunk", "nightly", "latest":
		return true
	default:
		return false
	}
}

func isVersionLikeBranch(branch string) bool {
	branch = strings.TrimSpace(branch)
	if branch == "" {
		return false
	}
	lower := strings.ToLower(strings.TrimPrefix(branch, "v"))
	if !strings.Contains(lower, ".") {
		return false
	}
	for _, r := range lower {
		if (r >= '0' && r <= '9') || r == '.' || r == 'x' {
			continue
		}
		return false
	}
	return true
}
