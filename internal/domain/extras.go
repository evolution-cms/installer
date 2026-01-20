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
	Name               string   `json:"name"`
	Version            string   `json:"version"`
	Versions           []string `json:"versions,omitempty"`
	Description        string   `json:"description"`
	DefaultInstallMode string   `json:"defaultInstallMode"`
	DefaultBranch      string   `json:"defaultBranch,omitempty"`
}

type ExtrasSelection struct {
	Name    string
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
