package domain

import "time"

type AppMode string

const (
	ModeInstall AppMode = "install"
	ModeDoctor  AppMode = "doctor"
)

type AppState struct {
	Mode AppMode

	Steps        []StepState
	SystemStatus SystemStatus
	Logs         LogState
	Progress     ProgressState
	Question     QuestionState
	Release      ReleaseState

	StartedAt time.Time
	EndedAt   *time.Time
}

type StatusLevel string

const (
	StatusOK    StatusLevel = "ok"
	StatusWarn  StatusLevel = "warn"
	StatusError StatusLevel = "error"
)

type StatusItem struct {
	Key     string
	Label   string
	Level   StatusLevel
	Details string
}

type SystemStatus struct {
	Items   []StatusItem
	Overall StatusLevel
	// Optional, display-oriented label (e.g. OK/Warnings/Errors).
	OverallLabel string
	UpdatedAt    time.Time
}

type ReleaseState struct {
	Highest ReleaseInfo
	Error   string
	Loading bool
}

type ReleaseInfo struct {
	Repo           string
	HighestVersion string
	Tag            string
	Name           string
	URL            string
	IsPrerelease   bool
	FetchedAt      time.Time
	Source         string // github_api / cache
}

type StepStatus string

const (
	StepPending StepStatus = "pending"
	StepActive  StepStatus = "active"
	StepDone    StepStatus = "done"
	StepWarn    StepStatus = "warn"
	StepError   StepStatus = "error"
)

type StepState struct {
	ID     string
	Label  string
	Status StepStatus
}

type DBDriverState struct {
	ID      string
	Label   string
	Enabled bool
	Reason  string
}

type LogLevel string

const (
	LogInfo    LogLevel = "info"
	LogWarning LogLevel = "warning"
	LogError   LogLevel = "error"
)

type LogEntry struct {
	TS      time.Time
	Level   LogLevel
	Source  string
	StepID  string
	Message string
	Fields  map[string]string
}

type LogState struct {
	Max     int
	Entries []LogEntry
}

type QuestionState struct {
	Active   bool
	ID       string
	Kind     QuestionKind
	Prompt   string
	Options  []QuestionOption
	Selected int

	// Input-mode only (Kind == QuestionInput).
	Default string
	Secret  bool
}

type QuestionKind string

const (
	QuestionSelect QuestionKind = "select"
	QuestionInput  QuestionKind = "input"
)

type QuestionOption struct {
	ID      string
	Label   string
	Enabled bool
	Reason  string
}

type ProgressState struct {
	StepID   string
	Current  int64
	Total    int64
	Unit     string
	Updated  time.Time
	Visible  bool
	Indicate bool
}
