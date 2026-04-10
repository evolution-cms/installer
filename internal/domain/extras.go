package domain

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
	Packages     []ExtrasPackage
	Selections   []ExtrasSelection
	Results      []ExtrasItemResult
	Current      string
	CurrentIndex int
	Total        int
	Details      []ExtrasItemDetail
}
