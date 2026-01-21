package install

import (
	"context"
	"fmt"
	"os"
	"strings"

	"github.com/evolution-cms/installer/internal/domain"
)

const (
	extrasPromptID     = "extras_prompt"
	extrasSelectID     = "extras_select"
	extrasStepID       = "extras"
	extrasSkipValue    = "skip"
	extrasInstallValue = "install"
)

func (e *Engine) maybeRunExtras(ctx context.Context, emit func(domain.Event) bool, actions <-chan domain.Action, workDir string) {
	if actions == nil {
		return
	}

	stepStarted := false
	stepOK := true
	startStep := func() {
		if stepStarted {
			return
		}
		stepStarted = true
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Step 7: Install Extras (optional)",
				Index: 8,
				Total: 8,
			},
		})
	}
	startStep()
	defer func() {
		if !stepStarted {
			return
		}
		sev := domain.SeverityInfo
		if !stepOK {
			sev = domain.SeverityWarn
		}
		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: sev,
			Payload:  domain.StepDonePayload{OK: stepOK},
		})
	}()

	coreDir, warn, err := checkExtrasPrereqs(ctx, workDir)
	if err != nil {
		_ = emit(domain.Event{
			Type:     domain.EventWarning,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityWarn,
			Payload: domain.LogPayload{
				Message: "Extras install skipped: " + err.Error(),
			},
		})
		stepOK = false
		return
	}
	if warn != "" {
		_ = emit(domain.Event{
			Type:     domain.EventWarning,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityWarn,
			Payload: domain.LogPayload{
				Message: warn,
			},
		})
	}

	preselected := e.opt.Extras
	if len(preselected) == 0 {
		choice, ok := askSelect(ctx, emit, actions, extrasStepID, extrasPromptQuestion())
		if !ok {
			return
		}
		if strings.ToLower(strings.TrimSpace(choice)) != "yes" {
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   extrasStepID,
				Source:   "extras",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: "Skipping extras installation.",
				},
			})
			emitExtrasSkippedSummary(emit)
			return
		}
	}

	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: "Fetching extras list...",
		},
	})

	token := strings.TrimSpace(e.opt.GithubPat)
	pkgs, err := fetchExtrasList(ctx, coreDir, token)
	if err != nil || len(pkgs) == 0 {
		msg := "Extras list unavailable."
		if err != nil {
			msg = "Extras list unavailable: " + err.Error()
		}
		_ = emit(domain.Event{
			Type:     domain.EventWarning,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityWarn,
			Payload: domain.LogPayload{
				Message: msg,
			},
		})
		stepOK = false
		if len(preselected) == 0 {
			return
		}
	}

	if len(preselected) == 0 {
		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.ExtrasState{
				Active:   true,
				Stage:    domain.ExtrasStageSelect,
				Packages: pkgs,
			},
		})
	}

	var selections []domain.ExtrasSelection
	if len(preselected) > 0 {
		selections = preselected
	} else {
		action, chosen, ok := waitExtrasDecision(ctx, actions)
		if !ok {
			return
		}
		if action != extrasInstallValue || len(chosen) == 0 {
			_ = emit(domain.Event{
				Type:     domain.EventExtras,
				StepID:   extrasStepID,
				Source:   "extras",
				Severity: domain.SeverityInfo,
				Payload: domain.ExtrasState{
					Active: false,
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   extrasStepID,
				Source:   "extras",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: "Extras installation skipped.",
				},
			})
			emitExtrasSkippedSummary(emit)
			return
		}
		selections = chosen
	}

	if len(pkgs) > 0 {
		selections = normalizeExtrasSelections(pkgs, selections)
	}
	if len(selections) == 0 {
		_ = emit(domain.Event{
			Type:     domain.EventWarning,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityWarn,
			Payload: domain.LogPayload{
				Message: "No valid extras selected; skipping.",
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.ExtrasState{
				Active: false,
			},
		})
		stepOK = false
		return
	}

	selectedLabels := make([]string, 0, len(selections))
	for _, sel := range selections {
		selectedLabels = append(selectedLabels, formatExtrasSelectionLabel(sel))
	}
	if len(preselected) > 0 {
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Extras preselected: " + strings.Join(selectedLabels, ", "),
			},
		})
	}
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: "Installing extras: " + strings.Join(selectedLabels, ", "),
		},
	})

	results := make([]domain.ExtrasItemResult, 0, len(selections)+2)
	for _, sel := range selections {
		results = append(results, domain.ExtrasItemResult{
			Name:   formatExtrasSelectionLabel(sel),
			Status: domain.ExtrasStatusPending,
		})
	}

	state := domain.ExtrasState{
		Active:     true,
		Stage:      domain.ExtrasStageProgress,
		Selections: selections,
		Results:    results,
		Total:      len(selections),
	}
	_ = emit(domain.Event{
		Type:     domain.EventExtras,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload:  state,
	})

	details := make([]domain.ExtrasItemDetail, 0, len(selections)+2)
	failFast := extrasFailFast()
	aborted := false

	for i, sel := range selections {
		label := formatExtrasSelectionLabel(sel)
		state.Current = label
		state.CurrentIndex = i + 1
		state.Results[i].Status = domain.ExtrasStatusRunning
		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})

		args := []string{"extras", "extras", sel.Name}
		if strings.TrimSpace(sel.Version) != "" {
			args = append(args, sel.Version)
		}
		args = append(args, "--no-ansi", "--no-interaction")
		out, err := runArtisanCommand(ctx, coreDir, token, args)
		emitExtrasOutputLogs(emit, extrasStepID, label, out)
		message := lastNonEmptyLine(out)
		detectedErr := detectExtrasFailure(out)
		if err != nil || detectedErr != "" {
			state.Results[i].Status = domain.ExtrasStatusError
			if detectedErr != "" {
				state.Results[i].Message = detectedErr
			} else {
				state.Results[i].Message = messageOrError(message, err)
			}
			if failFast {
				aborted = true
			}
		} else {
			state.Results[i].Status = domain.ExtrasStatusSuccess
		}
		detailOutput := tailOutput(out, 24)
		if strings.TrimSpace(detailOutput) == "" {
			detailOutput = "(no output captured)"
		}
		details = append(details, domain.ExtrasItemDetail{
			Name:   label,
			Output: detailOutput,
		})
		state.Details = details

		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})

		if err != nil && failFast {
			break
		}
	}

	if !aborted {
		state.Results = append(state.Results, domain.ExtrasItemResult{
			Name:   "artisan migrate",
			Status: domain.ExtrasStatusRunning,
		})
		state.Current = "artisan migrate"
		state.CurrentIndex = len(selections)
		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})

		out, err := runArtisanCommand(ctx, coreDir, token, []string{"migrate", "--force"})
		emitExtrasOutputLogs(emit, extrasStepID, "artisan migrate", out)
		migIdx := len(state.Results) - 1
		msg := lastNonEmptyLine(out)
		if err != nil {
			state.Results[migIdx].Status = domain.ExtrasStatusError
			state.Results[migIdx].Message = messageOrError(msg, err)
		} else {
			state.Results[migIdx].Status = domain.ExtrasStatusSuccess
		}
		if strings.TrimSpace(out) != "" {
			details = append(details, domain.ExtrasItemDetail{
				Name:   "artisan migrate",
				Output: tailOutput(out, 24),
			})
		}
		state.Details = details

		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})

		state.Results = append(state.Results, domain.ExtrasItemResult{
			Name:   "artisan cache:clear-full",
			Status: domain.ExtrasStatusRunning,
		})
		state.Current = "artisan cache:clear-full"
		state.CurrentIndex = len(selections)
		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})

		out, err = runArtisanCommand(ctx, coreDir, token, []string{"cache:clear-full"})
		emitExtrasOutputLogs(emit, extrasStepID, "artisan cache:clear-full", out)
		cacheIdx := len(state.Results) - 1
		msg = lastNonEmptyLine(out)
		if err != nil {
			state.Results[cacheIdx].Status = domain.ExtrasStatusError
			state.Results[cacheIdx].Message = messageOrError(msg, err)
		} else {
			state.Results[cacheIdx].Status = domain.ExtrasStatusSuccess
		}
		if strings.TrimSpace(out) != "" {
			details = append(details, domain.ExtrasItemDetail{
				Name:   "artisan cache:clear-full",
				Output: tailOutput(out, 24),
			})
		}
		state.Details = details

		_ = emit(domain.Event{
			Type:     domain.EventExtras,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload:  state,
		})
	}

	state.Stage = domain.ExtrasStageSummary
	state.Current = ""
	state.Details = details
	_ = emit(domain.Event{
		Type:     domain.EventExtras,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload:  state,
	})

	for _, r := range state.Results {
		if r.Status == domain.ExtrasStatusError {
			stepOK = false
			break
		}
	}
}

func extrasPromptQuestion() domain.QuestionState {
	return domain.QuestionState{
		Active: true,
		ID:     extrasPromptID,
		Kind:   domain.QuestionSelect,
		Prompt: "Do you want to install additional packages (Extras) now?",
		Options: []domain.QuestionOption{
			{ID: "yes", Label: "Yes", Enabled: true},
			{ID: "no", Label: "No", Enabled: true},
		},
		Selected: 0,
	}
}

func waitExtrasDecision(ctx context.Context, actions <-chan domain.Action) (string, []domain.ExtrasSelection, bool) {
	if actions == nil {
		return extrasSkipValue, nil, false
	}
	for {
		select {
		case <-ctx.Done():
			return extrasSkipValue, nil, false
		case a := <-actions:
			if a.Type != domain.ActionExtrasDecision || a.QuestionID != extrasSelectID {
				continue
			}
			if len(a.Extras) > 0 {
				return a.OptionID, a.Extras, true
			}
			if len(a.Values) > 0 {
				return a.OptionID, selectionsFromValues(a.Values), true
			}
			return a.OptionID, nil, true
		}
	}
}

func selectionsFromValues(values []string) []domain.ExtrasSelection {
	out := make([]domain.ExtrasSelection, 0, len(values))
	for _, v := range values {
		v = strings.TrimSpace(v)
		if v == "" {
			continue
		}
		name, version := splitSelectionValue(v)
		if name == "" {
			continue
		}
		out = append(out, domain.ExtrasSelection{Name: name, Version: version})
	}
	return out
}

func splitSelectionValue(value string) (string, string) {
	parts := strings.SplitN(value, "@", 2)
	name := strings.TrimSpace(parts[0])
	if len(parts) == 1 {
		return name, ""
	}
	return name, strings.TrimSpace(parts[1])
}

func normalizeExtrasSelections(pkgs []domain.ExtrasPackage, selections []domain.ExtrasSelection) []domain.ExtrasSelection {
	if len(selections) == 0 {
		return nil
	}
	allowed := map[string]struct{}{}
	pkgByName := map[string]domain.ExtrasPackage{}
	for _, p := range pkgs {
		if p.Name != "" {
			allowed[p.Name] = struct{}{}
			pkgByName[p.Name] = p
		}
	}
	out := make([]domain.ExtrasSelection, 0, len(selections))
	seen := map[string]int{}
	for _, sel := range selections {
		name := strings.TrimSpace(sel.Name)
		if name == "" {
			continue
		}
		if _, ok := allowed[name]; !ok {
			continue
		}
		version := strings.TrimSpace(sel.Version)
		if version == "" {
			if pkg, ok := pkgByName[name]; ok {
				version = strings.TrimSpace(defaultExtrasVersion(pkg))
			}
		}
		if idx, ok := seen[name]; ok {
			if out[idx].Version == "" && version != "" {
				out[idx].Version = version
			}
			continue
		}
		seen[name] = len(out)
		out = append(out, domain.ExtrasSelection{Name: name, Version: version})
	}
	return out
}

func formatExtrasSelectionLabel(sel domain.ExtrasSelection) string {
	name := strings.TrimSpace(sel.Name)
	version := strings.TrimSpace(sel.Version)
	if version == "" {
		return name
	}
	return name + "@" + version
}

func defaultExtrasVersion(pkg domain.ExtrasPackage) string {
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

func extrasFailFast() bool {
	v := strings.TrimSpace(os.Getenv("EVO_EXTRAS_FAIL_FAST"))
	if v == "" {
		return false
	}
	switch strings.ToLower(v) {
	case "1", "true", "yes", "y":
		return true
	default:
		return false
	}
}

func lastNonEmptyLine(out string) string {
	lines := strings.Split(strings.ReplaceAll(out, "\r\n", "\n"), "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		line := strings.TrimSpace(lines[i])
		if line != "" {
			return line
		}
	}
	return ""
}

func tailOutput(out string, maxLines int) string {
	if maxLines <= 0 {
		return ""
	}
	lines := strings.Split(strings.ReplaceAll(out, "\r\n", "\n"), "\n")
	start := len(lines) - maxLines
	if start < 0 {
		start = 0
	}
	chunk := lines[start:]
	for i := range chunk {
		chunk[i] = strings.TrimRight(chunk[i], "\r")
	}
	return strings.TrimSpace(strings.Join(chunk, "\n"))
}

func messageOrError(msg string, err error) string {
	msg = strings.TrimSpace(msg)
	if msg != "" {
		return msg
	}
	if err == nil {
		return ""
	}
	return fmt.Sprintf("%v", err)
}

func emitExtrasOutputLogs(emit func(domain.Event) bool, stepID string, label string, out string) {
	if emit == nil {
		return
	}
	out = strings.ReplaceAll(out, "\r\n", "\n")
	lines := strings.Split(out, "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		msg := line
		if label != "" {
			msg = label + ": " + line
		}
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   stepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: msg,
			},
		})
	}
}

func emitExtrasSkippedSummary(emit func(domain.Event) bool) {
	if emit == nil {
		return
	}
	state := domain.ExtrasState{
		Active: true,
		Stage:  domain.ExtrasStageSummary,
		Results: []domain.ExtrasItemResult{
			{
				Name:   "Extras skipped",
				Status: domain.ExtrasStatusSuccess,
			},
		},
	}
	_ = emit(domain.Event{
		Type:     domain.EventExtras,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload:  state,
	})
}

func detectExtrasFailure(out string) string {
	lines := strings.Split(strings.ReplaceAll(out, "\r\n", "\n"), "\n")
	hints := []string{
		"the limit that is provided for free use of github has been exceeded",
		"github api rate limit exceeded",
		"api rate limit exceeded",
		"rate limit exceeded",
		"authentication required",
		"requires authentication",
		"could not open input file",
		"no composer.json",
		"your requirements could not be resolved",
		"could not resolve host",
		"failed to download",
		"failed to open stream",
		"package operations: 0 installs, 0 updates, 0 removals",
	}

	for i := len(lines) - 1; i >= 0; i-- {
		raw := strings.TrimSpace(lines[i])
		if raw == "" {
			continue
		}
		lower := strings.ToLower(raw)
		for _, hint := range hints {
			if strings.Contains(lower, hint) {
				if hint == "package operations: 0 installs, 0 updates, 0 removals" {
					continue
				}
				return raw
			}
		}
		if strings.HasPrefix(lower, "fatal:") || strings.HasPrefix(lower, "error:") {
			return raw
		}
		if strings.Contains(lower, "exception") {
			return raw
		}
	}
	return ""
}
