package domain

import "time"

type EventType string

const (
	EventStepStart    EventType = "step_start"
	EventStepDone     EventType = "step_done"
	EventProgress     EventType = "progress"
	EventLog          EventType = "log"
	EventSteps        EventType = "steps"
	EventSystemStatus EventType = "system_status"
	EventWarning      EventType = "warning"
	EventError        EventType = "error"
	EventExecRequest  EventType = "exec_request"
)

type Severity string

const (
	SeverityTrace Severity = "trace"
	SeverityInfo  Severity = "info"
	SeverityWarn  Severity = "warn"
	SeverityError Severity = "error"
)

type Event struct {
	Type     EventType
	StepID   string
	TS       time.Time
	Source   string
	Severity Severity
	Payload  any
}

type StepStartPayload struct {
	Label string
	Index int
	Total int
}

type StepDonePayload struct {
	OK bool
}

type ProgressPayload struct {
	Current int64
	Total   int64
	Unit    string
}

type LogPayload struct {
	Message string
	Fields  map[string]string
}

type QuestionPayload struct {
	Question QuestionState
}

type StepsPayload struct {
	Steps []StepState
}

type SystemStatusEventPayload struct {
	SystemStatus SystemStatus
}

// ExecRequestPayload requests that the UI quits and then launches a command after
// Bubble Tea restores the terminal state.
type ExecRequestPayload struct {
	// Command is an argv-style slice: Command[0] is the executable, the rest are args.
	Command []string
}
