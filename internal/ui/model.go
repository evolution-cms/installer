package ui

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/charmbracelet/bubbles/progress"
	"github.com/charmbracelet/bubbles/spinner"
	"github.com/charmbracelet/bubbles/viewport"
	tea "github.com/charmbracelet/bubbletea"

	"github.com/evolution-cms/installer/internal/domain"
)

type EventMsg struct {
	Event domain.Event
	OK    bool
}

type pulseMsg struct{}

type Model struct {
	ctx     context.Context
	mode    Mode
	events  <-chan domain.Event
	cancel  func()
	actions chan<- domain.Action

	state domain.AppState
	meta  Meta

	width  int
	height int

	progress progress.Model

	questVP  viewport.Model
	statusVP viewport.Model
	logVP    viewport.Model

	layout layoutState

	spin spinner.Model

	// Gate initial render of the main UI until we receive the first system status payload.
	systemStatusLoading bool

	// Cancellation requested by the user (engine context is cancelled).
	cancelling bool

	// Events channel closed (engine finished or was cancelled).
	engineDone bool

	inputValue   string
	inputTouched bool

	// When true, keep the log viewport pinned to bottom as new logs arrive.
	followLogs bool

	pulseOn bool

	confirmQuitActive   bool
	confirmQuitSelected int // 0 = abort, 1 = continue
}

func NewModel(ctx context.Context, mode Mode, events <-chan domain.Event, actions chan<- domain.Action, meta Meta, cancel func()) *Model {
	now := time.Now()
	state := domain.AppState{
		Mode:      mode.DomainMode(),
		StartedAt: now,
		Logs: domain.LogState{
			Max:     1000,
			Entries: nil,
		},
		Release: domain.ReleaseState{
			Loading: true,
		},
	}

	spin := spinner.New()
	spin.Spinner = spinner.Line

	return &Model{
		ctx:                 ctx,
		mode:                mode,
		events:              events,
		cancel:              cancel,
		actions:             actions,
		state:               state,
		meta:                meta,
		progress:            progress.New(progress.WithSolidFill(progressFillHex), progress.WithoutPercentage()),
		spin:                spin,
		systemStatusLoading: true,
		followLogs:          true,
	}
}

func (m *Model) Init() tea.Cmd {
	return tea.Batch(
		waitForEvent(m.events),
		m.spin.Tick,
		pulseTick(),
	)
}

func (m *Model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height
		m.reflow()
		return m, nil

	case spinner.TickMsg:
		// Keep the spinner running while we're blocking on startup probes (release version / system status).
		if !(m.state.Release.Loading || m.systemStatusLoading) {
			return m, nil
		}
		var cmd tea.Cmd
		m.spin, cmd = m.spin.Update(msg)
		return m, cmd

	case pulseMsg:
		if m.hasActiveStep() && !m.engineDone && !m.cancelling {
			m.pulseOn = !m.pulseOn
			m.reflow()
		} else {
			m.pulseOn = false
		}
		return m, pulseTick()

	case tea.KeyMsg:
		key := msg.String()
		lowerKey := strings.ToLower(key)

		if m.confirmQuitActive {
			switch lowerKey {
			case "esc", "ctrl+q", "ctrl+й":
				m.confirmQuitActive = false
				m.confirmQuitSelected = 0
				m.reflow()
				return m, nil
			case "left", "up", "shift+tab":
				m.confirmQuitSelected = 0
				m.reflow()
				return m, nil
			case "right", "down", "tab":
				m.confirmQuitSelected = 1
				m.reflow()
				return m, nil
			case "enter":
				if m.confirmQuitSelected == 0 {
					if m.cancel != nil {
						m.cancel()
					}
					return m, tea.Quit
				}
				m.confirmQuitActive = false
				m.confirmQuitSelected = 0
				m.reflow()
				return m, nil
			default:
				return m, nil
			}
		}

		switch lowerKey {
		case "ctrl+q", "ctrl+й":
			if m.engineDone {
				return m, tea.Quit
			}
			m.confirmQuitActive = true
			m.confirmQuitSelected = 0
			m.reflow()
			return m, nil
		case "ctrl+c", "ctrl+с":
			// ctrl+c: request cancellation (first press), then quit (second press).
			if !m.cancelling {
				if lowerKey == "ctrl+с" {
					m.requestCancel("ctrl+с")
				} else {
					m.requestCancel("ctrl+c")
				}
				m.reflow()
				return m, nil
			}
			return m, tea.Quit
		case "pgup", "pageup":
			m.followLogs = false
			m.logVP.LineUp(m.logVP.Height)
			m.reflow()
			return m, nil
		case "pgdown", "pagedown":
			m.logVP.LineDown(m.logVP.Height)
			if m.logVP.AtBottom() {
				m.followLogs = true
			}
			m.reflow()
			return m, nil
		case "home":
			m.followLogs = false
			m.logVP.GotoTop()
			m.reflow()
			return m, nil
		case "end":
			m.followLogs = true
			m.logVP.GotoBottom()
			m.reflow()
			return m, nil
		}

		// Question handling (engine-driven).
		if m.state.Question.Active {
			kind := m.state.Question.Kind
			if kind == "" {
				kind = domain.QuestionSelect
			}

			if kind == domain.QuestionInput {
				if key == "enter" {
					text := m.inputValue
					if !m.inputTouched {
						text = m.state.Question.Default
					}
					m.sendAction(domain.Action{
						Type:       domain.ActionAnswerInput,
						QuestionID: m.state.Question.ID,
						Text:       text,
					})
					m.state.Question.Active = false
					m.inputValue = ""
					m.inputTouched = false
					m.reflow()
					return m, nil
				}

				switch msg.Type {
				case tea.KeyRunes:
					if !m.inputTouched {
						m.inputValue = string(msg.Runes)
						m.inputTouched = true
					} else {
						m.inputValue += string(msg.Runes)
					}
				case tea.KeyCtrlU:
					m.inputTouched = true
					m.inputValue = ""
				case tea.KeyBackspace:
					if m.inputTouched {
						rs := []rune(m.inputValue)
						if len(rs) > 0 {
							m.inputValue = string(rs[:len(rs)-1])
						}
					}
				default:
					// Bubble Tea versions differ in key constants; handle common backspace strings.
					switch key {
					case "backspace", "ctrl+h":
						if m.inputTouched {
							rs := []rune(m.inputValue)
							if len(rs) > 0 {
								m.inputValue = string(rs[:len(rs)-1])
							}
						}
					}
				}
				m.reflow()
				return m, nil
			}

			switch key {
			case "up":
				m.state.Question.Selected = selectPrevEnabled(m.state.Question)
				m.reflow()
				return m, nil
			case "down":
				m.state.Question.Selected = selectNextEnabled(m.state.Question)
				m.reflow()
				return m, nil
			case "enter":
				if len(m.state.Question.Options) == 0 {
					return m, nil
				}
				i := m.state.Question.Selected
				if i >= 0 && i < len(m.state.Question.Options) && m.state.Question.Options[i].Enabled {
					opt := m.state.Question.Options[i]
					m.sendAction(domain.Action{
						Type:       domain.ActionAnswerSelect,
						QuestionID: m.state.Question.ID,
						OptionID:   opt.ID,
					})
					m.state.Question.Active = false
					m.inputValue = ""
					m.inputTouched = false
					m.reflow()
				}
				return m, nil
			}
		}

		// No active question: arrows scroll the log viewport.
		switch key {
		case "up":
			m.followLogs = false
			m.logVP.LineUp(1)
			m.reflow()
			return m, nil
		case "down":
			m.logVP.LineDown(1)
			if m.logVP.AtBottom() {
				m.followLogs = true
			}
			m.reflow()
			return m, nil
		}

		return m, nil

	case EventMsg:
		if !msg.OK {
			// Engine finished; keep UI open until user quits.
			m.engineDone = true
			m.state.Release.Loading = false
			m.systemStatusLoading = false
			m.reflow()
			return m, nil
		}
		m.applyEvent(msg.Event)
		m.reflow()
		return m, waitForEvent(m.events)

	default:
		return m, nil
	}
}

func (m *Model) requestCancel(key string) {
	m.cancelling = true
	m.state.Question.Active = false
	m.state.Logs.Entries = append(m.state.Logs.Entries, domain.LogEntry{
		TS:      time.Now(),
		Level:   domain.LogWarning,
		Source:  "ui",
		StepID:  "",
		Message: fmt.Sprintf("Cancellation requested (%s).", key),
	})
	if m.state.Logs.Max > 0 && len(m.state.Logs.Entries) > m.state.Logs.Max {
		m.state.Logs.Entries = m.state.Logs.Entries[len(m.state.Logs.Entries)-m.state.Logs.Max:]
	}
	if m.cancel != nil {
		m.cancel()
	}
}

func (m *Model) applyEvent(ev domain.Event) {
	switch ev.Type {
	case domain.EventSteps:
		switch p := ev.Payload.(type) {
		case domain.StepsPayload:
			m.state.Steps = cloneSteps(p.Steps)
		case []domain.StepState:
			m.state.Steps = cloneSteps(p)
		}
	case domain.EventStepStart:
		if !isInternalStep(ev.StepID) {
			m.setStepActive(ev)
		}
		if ev.StepID == "fetch_release_version" {
			m.state.Release.Loading = true
			m.state.Release.Error = ""
		}
	case domain.EventStepDone:
		if !isInternalStep(ev.StepID) {
			m.setStepDone(ev)
		}
		if ev.StepID == "fetch_release_version" {
			m.state.Release.Loading = false
			if info, ok := ev.Payload.(domain.ReleaseInfo); ok {
				m.state.Release.Highest = info
				m.state.Release.Error = ""
			}
		}
	case domain.EventProgress:
		m.setProgress(ev)
	case domain.EventSystemStatus:
		if p, ok := ev.Payload.(domain.SystemStatus); ok {
			m.state.SystemStatus = domain.NormalizeSystemStatus(p)
		} else if p, ok := ev.Payload.(domain.SystemStatusEventPayload); ok {
			m.state.SystemStatus = domain.NormalizeSystemStatus(p.SystemStatus)
		}
		m.systemStatusLoading = false
	case domain.EventWarning:
		m.addLog(ev, domain.LogWarning)
		m.setStepWarn(ev.StepID)
		if ev.StepID == "fetch_release_version" {
			m.state.Release.Loading = false
			if p, ok := ev.Payload.(domain.LogPayload); ok && p.Message != "" {
				m.state.Release.Error = p.Message
			} else {
				m.state.Release.Error = "warning"
			}
		}
		if ev.StepID == "check_system_status" {
			// If the adapter fails before emitting a status payload, don't block UI forever.
			m.systemStatusLoading = false
		}
	case domain.EventError:
		m.addLog(ev, domain.LogError)
		m.setStepError(ev.StepID)
		if ev.StepID == "fetch_release_version" {
			m.state.Release.Loading = false
			if p, ok := ev.Payload.(domain.LogPayload); ok && p.Message != "" {
				m.state.Release.Error = p.Message
			} else {
				m.state.Release.Error = "error"
			}
		}
		if ev.StepID == "check_system_status" {
			m.systemStatusLoading = false
		}
	case domain.EventLog:
		// Can be a plain log or structured state update.
		switch payload := ev.Payload.(type) {
		case domain.QuestionPayload:
			m.state.Question = payload.Question
			if m.state.Question.Kind == "" {
				m.state.Question.Kind = domain.QuestionSelect
			}
			if m.state.Question.Kind == domain.QuestionInput {
				m.inputValue = ""
				m.inputTouched = false
			} else {
				m.inputValue = ""
				m.inputTouched = false
			}
		case domain.LogPayload:
			m.addLog(ev, domain.LogInfo)
		default:
			// Ignore unknown payload types; UI must not parse text.
		}
	}
}

func (m *Model) sendAction(a domain.Action) {
	if m.actions == nil {
		return
	}
	select {
	case m.actions <- a:
	default:
	}
}

func cloneSteps(in []domain.StepState) []domain.StepState {
	if len(in) == 0 {
		return nil
	}
	out := make([]domain.StepState, len(in))
	copy(out, in)
	return out
}

func isInternalStep(stepID string) bool {
	switch stepID {
	case "fetch_release_version", "check_system_status":
		return true
	default:
		return false
	}
}

func (m *Model) addLog(ev domain.Event, level domain.LogLevel) {
	payload, ok := ev.Payload.(domain.LogPayload)
	if !ok {
		return
	}

	// Structured in-place updates (used for inline progress lines).
	if payload.Fields != nil {
		switch payload.Fields["op"] {
		case "replace_last":
			if len(m.state.Logs.Entries) > 0 {
				last := &m.state.Logs.Entries[len(m.state.Logs.Entries)-1]
				last.TS = ev.TS
				last.Level = level
				last.Source = ev.Source
				last.StepID = ev.StepID
				last.Message = payload.Message
				last.Fields = payload.Fields
				return
			}
		case "replace_last_if_same":
			if len(m.state.Logs.Entries) > 0 {
				key := payload.Fields["progress_key"]
				last := m.state.Logs.Entries[len(m.state.Logs.Entries)-1]
				if last.Fields != nil && last.Fields["kind"] == payload.Fields["kind"] && last.Fields["progress_key"] == key {
					m.state.Logs.Entries[len(m.state.Logs.Entries)-1] = domain.LogEntry{
						TS:      ev.TS,
						Level:   level,
						Source:  ev.Source,
						StepID:  ev.StepID,
						Message: payload.Message,
						Fields:  payload.Fields,
					}
					return
				}
			}
		}
	}

	entry := domain.LogEntry{
		TS:      ev.TS,
		Level:   level,
		Source:  ev.Source,
		StepID:  ev.StepID,
		Message: payload.Message,
		Fields:  payload.Fields,
	}
	m.state.Logs.Entries = append(m.state.Logs.Entries, entry)
	if m.state.Logs.Max > 0 && len(m.state.Logs.Entries) > m.state.Logs.Max {
		m.state.Logs.Entries = m.state.Logs.Entries[len(m.state.Logs.Entries)-m.state.Logs.Max:]
	}
}

func (m *Model) setStepActive(ev domain.Event) {
	label := ev.StepID
	if p, ok := ev.Payload.(domain.StepStartPayload); ok && p.Label != "" {
		label = p.Label
	}

	found := false
	for idx := range m.state.Steps {
		if m.state.Steps[idx].ID == ev.StepID {
			m.state.Steps[idx].Label = label
			m.state.Steps[idx].Status = domain.StepActive
			found = true
		} else if m.state.Steps[idx].Status == domain.StepActive {
			m.state.Steps[idx].Status = domain.StepPending
		}
	}
	if !found && ev.StepID != "" {
		m.state.Steps = append(m.state.Steps, domain.StepState{ID: ev.StepID, Label: label, Status: domain.StepActive})
	}
}

func (m *Model) setStepDone(ev domain.Event) {
	ok := true
	if p, ok2 := ev.Payload.(domain.StepDonePayload); ok2 {
		ok = p.OK
	}
	for idx := range m.state.Steps {
		if m.state.Steps[idx].ID == ev.StepID {
			if ok {
				m.state.Steps[idx].Status = domain.StepDone
			} else if m.state.Steps[idx].Status != domain.StepError {
				m.state.Steps[idx].Status = domain.StepWarn
			}
		}
	}
	if allDone(m.state.Steps) && m.state.EndedAt == nil {
		t := time.Now()
		m.state.EndedAt = &t
	}
}

func (m *Model) setStepWarn(stepID string) {
	for idx := range m.state.Steps {
		if m.state.Steps[idx].ID == stepID && m.state.Steps[idx].Status != domain.StepError {
			m.state.Steps[idx].Status = domain.StepWarn
		}
	}
}

func (m *Model) setStepError(stepID string) {
	for idx := range m.state.Steps {
		if m.state.Steps[idx].ID == stepID {
			m.state.Steps[idx].Status = domain.StepError
		}
	}
}

func (m *Model) setProgress(ev domain.Event) {
	p, ok := ev.Payload.(domain.ProgressPayload)
	if !ok {
		return
	}
	m.state.Progress = domain.ProgressState{
		StepID:  ev.StepID,
		Current: p.Current,
		Total:   p.Total,
		Unit:    p.Unit,
		Updated: time.Now(),
		Visible: true,
	}
}

func allDone(steps []domain.StepState) bool {
	if len(steps) == 0 {
		return false
	}
	for _, s := range steps {
		if s.Status != domain.StepDone && s.Status != domain.StepWarn {
			return false
		}
	}
	return true
}

func waitForEvent(events <-chan domain.Event) tea.Cmd {
	return func() tea.Msg {
		ev, ok := <-events
		return EventMsg{Event: ev, OK: ok}
	}
}

func pulseTick() tea.Cmd {
	return tea.Tick(500*time.Millisecond, func(time.Time) tea.Msg { return pulseMsg{} })
}

func (m *Model) hasActiveStep() bool {
	for _, s := range m.state.Steps {
		if s.Status == domain.StepActive {
			return true
		}
	}
	return false
}

func selectPrevEnabled(q domain.QuestionState) int {
	if len(q.Options) == 0 {
		return 0
	}
	i := q.Selected
	for step := 0; step < len(q.Options); step++ {
		i--
		if i < 0 {
			i = len(q.Options) - 1
		}
		if q.Options[i].Enabled {
			return i
		}
	}
	return q.Selected
}

func selectNextEnabled(q domain.QuestionState) int {
	if len(q.Options) == 0 {
		return 0
	}
	i := q.Selected
	for step := 0; step < len(q.Options); step++ {
		i++
		if i >= len(q.Options) {
			i = 0
		}
		if q.Options[i].Enabled {
			return i
		}
	}
	return q.Selected
}
