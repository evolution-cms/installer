package install

import (
	"context"
	"fmt"
	"os"
	"strings"

	"github.com/evolution-cms/installer/internal/domain"
)

const (
	extrasSelectID     = "extras_select"
	extrasStepID       = "extras"
	extrasSkipValue    = "skip"
	extrasInstallValue = "install"
)

func (e *Engine) maybeRunExtras(ctx context.Context, emit func(domain.Event) bool, actions <-chan domain.Action, workDir string, requiredExtras []domain.ExtrasSelection) {
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
				Label: "Step 8: Install Extras (optional)",
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
	if len(requiredExtras) > 0 {
		labels := make([]string, 0, len(requiredExtras))
		for _, sel := range requiredExtras {
			labels = append(labels, formatExtrasSelectionLabel(sel))
		}
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Preset requires extras: " + strings.Join(labels, ", "),
			},
		})
	}
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   extrasStepID,
		Source:   "extras",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: "Fetching extras catalogs...",
		},
	})

	token := strings.TrimSpace(e.opt.GithubPat)
	pkgs, defaults, warnings, err := loadAllExtrasCatalogs(ctx, workDir, token)
	normalizedRequired := requiredExtras
	if len(pkgs) > 0 && len(requiredExtras) > 0 {
		normalizedRequired = normalizeExtrasSelections(pkgs, requiredExtras)
		if len(normalizedRequired) == 0 {
			normalizedRequired = requiredExtras
		}
	}
	pkgs = markRequiredExtrasPackages(pkgs, normalizedRequired)
	defaults = mergeRequiredExtras(defaults, normalizedRequired)
	if len(pkgs) > 0 {
		defaults = normalizeExtrasSelections(pkgs, defaults)
	}
	for _, msg := range warnings {
		_ = emit(domain.Event{
			Type:     domain.EventWarning,
			StepID:   extrasStepID,
			Source:   "extras",
			Severity: domain.SeverityWarn,
			Payload: domain.LogPayload{
				Message: msg,
			},
		})
	}
	if err != nil || len(pkgs) == 0 {
		msg := "Extras catalogs unavailable."
		if err != nil {
			msg = "Extras catalogs unavailable: " + err.Error()
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
		if len(preselected) == 0 && len(requiredExtras) == 0 {
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
				Active:     true,
				Stage:      domain.ExtrasStageSelect,
				Packages:   pkgs,
				Selections: defaults,
			},
		})
	}

	var selections []domain.ExtrasSelection
	if len(preselected) > 0 {
		selections = mergeRequiredExtras(preselected, normalizedRequired)
	} else {
		action, chosen, ok := waitExtrasDecision(ctx, actions)
		if !ok {
			return
		}
		if action != extrasInstallValue || len(chosen) == 0 {
			if len(normalizedRequired) > 0 {
				selections = mergeRequiredExtras(nil, normalizedRequired)
			} else {
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
		} else {
			selections = mergeRequiredExtras(chosen, normalizedRequired)
		}
		if len(selections) == 0 {
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
	pkgByID := map[string]domain.ExtrasPackage{}
	for _, pkg := range pkgs {
		if id := strings.TrimSpace(pkg.ID); id != "" {
			pkgByID[id] = pkg
		}
	}
	for _, sel := range selections {
		results = append(results, domain.ExtrasItemResult{
			Name:   formatExtrasSelectionLabel(sel),
			Status: domain.ExtrasStatusPending,
		})
	}

	state := domain.ExtrasState{
		Active:      true,
		Stage:       domain.ExtrasStageProgress,
		ProjectPath: workDir,
		Selections:  selections,
		Results:     results,
		Total:       len(selections),
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

		out, err := runExtrasSelection(ctx, coreDir, token, pkgByID, sel)
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
		id, version := splitSelectionValue(v)
		if id == "" {
			continue
		}
		out = append(out, domain.ExtrasSelection{ID: id, Version: version})
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
	pkgByID := map[string]domain.ExtrasPackage{}
	pkgByName := map[string]domain.ExtrasPackage{}
	for _, p := range pkgs {
		if p.ID != "" {
			allowed[p.ID] = struct{}{}
			pkgByID[p.ID] = p
		}
		if p.Name != "" {
			pkgByName[strings.ToLower(p.Name)] = p
		}
	}
	out := make([]domain.ExtrasSelection, 0, len(selections))
	seen := map[string]int{}
	for _, sel := range selections {
		id := strings.TrimSpace(sel.ID)
		pkg := domain.ExtrasPackage{}
		var ok bool
		if id != "" {
			pkg, ok = pkgByID[id]
		}
		if !ok {
			name := strings.ToLower(strings.TrimSpace(sel.Name))
			if name == "" {
				continue
			}
			pkg, ok = pkgByName[name]
		}
		if !ok {
			continue
		}
		id = strings.TrimSpace(pkg.ID)
		if _, ok := allowed[id]; !ok {
			continue
		}
		version := strings.TrimSpace(sel.Version)
		if version == "" {
			version = strings.TrimSpace(defaultExtrasInstallVersion(pkg))
		} else {
			version = strings.TrimSpace(domain.NormalizeExtrasInstallVersion(pkg, version))
		}
		if idx, ok := seen[id]; ok {
			if out[idx].Version == "" && version != "" {
				out[idx].Version = version
			}
			continue
		}
		seen[id] = len(out)
		out = append(out, domain.ExtrasSelection{
			ID:       id,
			Name:     pkg.Name,
			Source:   pkg.Source,
			Version:  version,
			Required: sel.Required || pkg.Required,
		})
	}
	return out
}

func mergeRequiredExtras(selections []domain.ExtrasSelection, required []domain.ExtrasSelection) []domain.ExtrasSelection {
	if len(selections) == 0 && len(required) == 0 {
		return nil
	}

	out := make([]domain.ExtrasSelection, 0, len(selections)+len(required))
	seen := map[string]int{}
	add := func(sel domain.ExtrasSelection, forceRequired bool) {
		key := extrasSelectionIdentity(sel)
		if key == "" {
			return
		}
		if forceRequired {
			sel.Required = true
		}
		if idx, ok := seen[key]; ok {
			if out[idx].Version == "" && strings.TrimSpace(sel.Version) != "" {
				out[idx].Version = strings.TrimSpace(sel.Version)
			}
			out[idx].Required = out[idx].Required || sel.Required
			return
		}
		seen[key] = len(out)
		out = append(out, sel)
	}

	for _, sel := range selections {
		add(sel, false)
	}
	for _, sel := range required {
		add(sel, true)
	}
	return out
}

func markRequiredExtrasPackages(pkgs []domain.ExtrasPackage, required []domain.ExtrasSelection) []domain.ExtrasPackage {
	if len(pkgs) == 0 || len(required) == 0 {
		return pkgs
	}
	requiredKeys := map[string]struct{}{}
	for _, sel := range required {
		if key := strings.ToLower(strings.TrimSpace(sel.ID)); key != "" {
			requiredKeys[key] = struct{}{}
		}
		if key := strings.ToLower(strings.TrimSpace(sel.Name)); key != "" {
			requiredKeys[key] = struct{}{}
		}
	}
	for i := range pkgs {
		_, idRequired := requiredKeys[strings.ToLower(strings.TrimSpace(pkgs[i].ID))]
		_, nameRequired := requiredKeys[strings.ToLower(strings.TrimSpace(pkgs[i].Name))]
		if idRequired || nameRequired {
			pkgs[i].Required = true
		}
	}
	return pkgs
}

func extrasSelectionIdentity(sel domain.ExtrasSelection) string {
	if id := strings.ToLower(strings.TrimSpace(sel.ID)); id != "" {
		return id
	}
	return strings.ToLower(strings.TrimSpace(sel.Name))
}

func defaultExtrasInstallVersion(pkg domain.ExtrasPackage) string {
	return domain.DefaultExtrasInstallVersion(pkg)
}

func isManagedExtrasPackage(pkg domain.ExtrasPackage) bool {
	return domain.IsManagedExtrasPackage(pkg)
}

func formatExtrasSelectionLabel(sel domain.ExtrasSelection) string {
	name := strings.TrimSpace(sel.Name)
	if name == "" {
		name = strings.TrimSpace(sel.ID)
	}
	version := strings.TrimSpace(sel.Version)
	prefix := ""
	switch strings.TrimSpace(sel.Source) {
	case "bundled-inline":
		prefix = "[bundled] "
	case "legacy-store":
		prefix = "[legacy] "
	}
	if version == "" {
		return prefix + name
	}
	return prefix + name + "@" + version
}

func runExtrasSelection(ctx context.Context, coreDir string, token string, pkgByID map[string]domain.ExtrasPackage, sel domain.ExtrasSelection) (string, error) {
	pkg, ok := pkgByID[strings.TrimSpace(sel.ID)]
	if !ok {
		args := []string{"extras", "extras", sel.Name}
		if strings.TrimSpace(sel.Version) != "" {
			args = append(args, sel.Version)
		}
		args = append(args, "--no-ansi", "--no-interaction")
		return runArtisanCommand(ctx, coreDir, token, args)
	}

	switch pkg.InstallMode {
	case "bundled-inline":
		payload := map[string]any{
			"items": []domain.ExtrasPackage{pkg},
		}
		return runExtrasHelper(ctx, coreDir, "bundled-inline", payload)
	case "legacy-store-zip":
		if strings.TrimSpace(pkg.DownloadURL) == "" {
			return "", fmt.Errorf("legacy store package is missing a download URL")
		}
		payload := map[string]any{
			"item": map[string]any{
				"name":         pkg.Name,
				"downloadUrl":  pkg.DownloadURL,
				"dependencies": pkg.Dependencies,
			},
		}
		return runExtrasHelper(ctx, coreDir, "legacy-store", payload)
	default:
		args := []string{"extras", "extras", pkg.Name}
		version := strings.TrimSpace(sel.Version)
		if version != "" {
			args = append(args, version)
		}
		args = append(args, "--no-ansi", "--no-interaction")
		return runArtisanCommand(ctx, coreDir, token, args)
	}
}

func defaultExtrasVersion(pkg domain.ExtrasPackage) string {
	return domain.DefaultExtrasVersion(pkg)
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

	priorityHints := []string{
		"sqlstate[",
		"thrown in ",
	}
	for _, raw := range lines {
		raw = strings.TrimSpace(raw)
		if raw == "" {
			continue
		}
		lower := strings.ToLower(raw)
		for _, hint := range priorityHints {
			if strings.Contains(lower, hint) {
				return raw
			}
		}
		if strings.HasPrefix(lower, "fatal:") || strings.HasPrefix(lower, "error:") {
			return raw
		}
	}

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
		"stack trace:",
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
		if strings.HasSuffix(lower, " fail") {
			return raw
		}
		if strings.Contains(lower, "exception") {
			return raw
		}
	}
	return ""
}
