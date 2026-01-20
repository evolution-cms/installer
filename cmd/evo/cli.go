package main

import (
	"context"
	"errors"
	"fmt"
	"net/mail"
	"os"
	"strings"

	"github.com/evolution-cms/installer/internal/domain"
	installengine "github.com/evolution-cms/installer/internal/engine/install"
	"github.com/evolution-cms/installer/internal/logging"
)

func applyCLIDefaults(opt *installengine.Options) error {
	if opt == nil {
		return errors.New("missing installer options")
	}

	dbType := strings.ToLower(strings.TrimSpace(opt.DBType))
	if dbType == "" {
		return errors.New("CLI mode requires --db-type")
	}
	if !isAllowedDBType(dbType) {
		return fmt.Errorf("CLI mode requires --db-type to be one of: mysql, pgsql, sqlite, sqlsrv (got %q)", dbType)
	}
	opt.DBType = dbType

	if strings.TrimSpace(opt.DBName) == "" {
		return errors.New("CLI mode requires --db-name")
	}

	if dbType != "sqlite" {
		if strings.TrimSpace(opt.DBHost) == "" {
			opt.DBHost = "localhost"
		}
		if strings.TrimSpace(opt.DBUser) == "" {
			opt.DBUser = "root"
		}
	}

	if strings.TrimSpace(opt.AdminUsername) == "" {
		opt.AdminUsername = "admin"
	}
	if strings.TrimSpace(opt.AdminDirectory) == "" {
		opt.AdminDirectory = "manager"
	}
	if strings.TrimSpace(opt.Language) == "" {
		opt.Language = "en"
	}

	adminEmail := strings.TrimSpace(opt.AdminEmail)
	if adminEmail == "" {
		return errors.New("CLI mode requires --admin-email")
	}
	if _, err := mail.ParseAddress(adminEmail); err != nil {
		return fmt.Errorf("CLI mode requires a valid --admin-email: %v", err)
	}
	opt.AdminEmail = adminEmail

	adminPassword := strings.TrimSpace(opt.AdminPassword)
	if adminPassword == "" {
		return errors.New("CLI mode requires --admin-password")
	}
	if len([]rune(adminPassword)) < 6 {
		return errors.New("--admin-password must be at least 6 characters long")
	}
	opt.AdminPassword = adminPassword

	return nil
}

func isAllowedDBType(dbType string) bool {
	switch dbType {
	case "mysql", "pgsql", "sqlite", "sqlsrv":
		return true
	default:
		return false
	}
}

func runCLI(ctx context.Context, events <-chan domain.Event, actions chan<- domain.Action, cancel func(), logger *logging.EventLogger, quiet bool) ([]string, error) {
	fmt.Fprintln(os.Stdout, "Running installer in CLI mode (no TUI).")

	stepLabels := map[string]string{}
	state := &cliState{lastLogByStep: map[string]string{}}
	var postExec []string
	var hadError bool

	for {
		select {
		case <-ctx.Done():
			return postExec, fmt.Errorf("installation cancelled: %w", ctx.Err())
		case ev, ok := <-events:
			if !ok {
				if hadError {
					return postExec, errors.New("installation failed")
				}
				return postExec, nil
			}
			if logger != nil {
				logger.Record(ev)
			}
			if applyCLIEvent(ev, stepLabels, actions, cancel, &hadError, quiet, state) {
				continue
			}
			if ev.Type == domain.EventExecRequest {
				if p, ok := ev.Payload.(domain.ExecRequestPayload); ok && len(p.Command) > 0 {
					postExec = append([]string(nil), p.Command...)
				}
			}
		}
	}
}

func applyCLIEvent(ev domain.Event, stepLabels map[string]string, actions chan<- domain.Action, cancel func(), hadError *bool, quiet bool, state *cliState) bool {
	switch ev.Type {
	case domain.EventSteps:
		switch p := ev.Payload.(type) {
		case domain.StepsPayload:
			for _, s := range p.Steps {
				if s.ID != "" && s.Label != "" {
					stepLabels[s.ID] = s.Label
				}
			}
		case []domain.StepState:
			for _, s := range p {
				if s.ID != "" && s.Label != "" {
					stepLabels[s.ID] = s.Label
				}
			}
		}
	case domain.EventStepStart:
		label := stepLabel(stepLabels, ev.StepID, ev.Payload)
		printCLILine("==>", ev.StepID, label, os.Stdout)
	case domain.EventStepDone:
		label := stepLabel(stepLabels, ev.StepID, ev.Payload)
		ok := true
		if p, okPayload := ev.Payload.(domain.StepDonePayload); okPayload {
			ok = p.OK
		}
		if !ok {
			*hadError = true
			printCLILine("✗", ev.StepID, label, os.Stderr)
		} else {
			printCLILine("✓", ev.StepID, label, os.Stdout)
		}
	case domain.EventProgress:
		if quiet {
			return false
		}
		if p, ok := ev.Payload.(domain.ProgressPayload); ok {
			unit := strings.TrimSpace(p.Unit)
			if unit == "" {
				unit = "units"
			}
			msg := fmt.Sprintf("Progress: %d/%d %s", p.Current, p.Total, unit)
			printCLILine("•", ev.StepID, msg, os.Stdout)
		}
	case domain.EventLog:
		switch payload := ev.Payload.(type) {
		case domain.QuestionPayload:
			return handleCLIQuestion(payload.Question, actions, cancel, hadError)
		case domain.LogPayload:
			msg := formatCLILogMessage(payload)
			if msg != "" {
				if shouldSkipCLILog(payload.Fields, ev.StepID, msg, state) {
					return false
				}
				if quiet && !shouldPrintQuiet(msg) {
					return false
				}
				printCLILine("-", ev.StepID, msg, os.Stdout)
			}
		}
	case domain.EventWarning:
		if p, ok := ev.Payload.(domain.LogPayload); ok {
			msg := formatCLILogMessage(p)
			if msg != "" {
				if shouldSkipCLILog(p.Fields, ev.StepID, msg, state) {
					return false
				}
				if quiet && !shouldPrintQuiet(msg) {
					return false
				}
				printCLILine("!", ev.StepID, msg, os.Stderr)
			}
		}
	case domain.EventError:
		*hadError = true
		if p, ok := ev.Payload.(domain.LogPayload); ok {
			msg := formatCLILogMessage(p)
			if msg != "" {
				printCLILine("✗", ev.StepID, msg, os.Stderr)
			}
		}
	}
	return false
}

func handleCLIQuestion(q domain.QuestionState, actions chan<- domain.Action, cancel func(), hadError *bool) bool {
	switch q.ID {
	case "self_update":
		sendAction(actions, domain.Action{
			Type:       domain.ActionAnswerSelect,
			QuestionID: q.ID,
			OptionID:   "skip",
		})
		fmt.Fprintln(os.Stdout, "Installer update available; skipping in --cli mode.")
		return true
	case "db_retry":
		sendAction(actions, domain.Action{
			Type:       domain.ActionAnswerSelect,
			QuestionID: q.ID,
			OptionID:   "exit",
		})
		*hadError = true
		fmt.Fprintln(os.Stderr, "Database connection failed; exiting (no retry in --cli mode).")
		return true
	default:
		*hadError = true
		fmt.Fprintln(os.Stderr, cliMissingInputMessage(q))
		if cancel != nil {
			cancel()
		}
		return true
	}
}

func cliMissingInputMessage(q domain.QuestionState) string {
	flag := ""
	switch q.ID {
	case "db_type":
		flag = "--db-type"
	case "db_host":
		flag = "--db-host"
	case "db_name", "db_sqlite_path":
		flag = "--db-name"
	case "db_user":
		flag = "--db-user"
	case "db_password":
		flag = "--db-password"
	case "admin_username":
		flag = "--admin-username"
	case "admin_email":
		flag = "--admin-email"
	case "admin_password":
		flag = "--admin-password"
	case "admin_directory":
		flag = "--admin-directory"
	case "language":
		flag = "--language"
	}
	if flag != "" {
		return "CLI mode is non-interactive; provide " + flag + " to continue."
	}
	return "CLI mode is non-interactive; missing required input."
}

func sendAction(actions chan<- domain.Action, a domain.Action) {
	if actions == nil {
		return
	}
	select {
	case actions <- a:
	default:
	}
}

func printCLILine(prefix, stepID, message string, out *os.File) {
	stepID = strings.TrimSpace(stepID)
	message = strings.TrimSpace(message)
	if message == "" {
		return
	}
	if stepID != "" {
		fmt.Fprintf(out, "%s [%s] %s\n", prefix, stepID, message)
		return
	}
	fmt.Fprintf(out, "%s %s\n", prefix, message)
}

type cliState struct {
	lastLogByStep map[string]string
}

func shouldSkipCLILog(fields map[string]string, stepID string, message string, state *cliState) bool {
	if state == nil || message == "" {
		return false
	}
	op := strings.ToLower(strings.TrimSpace(""))
	if fields != nil {
		op = strings.ToLower(strings.TrimSpace(fields["op"]))
	}
	if op != "replace_last" && op != "replace_last_if_same" {
		state.lastLogByStep[stepID] = message
		return false
	}
	last := state.lastLogByStep[stepID]
	state.lastLogByStep[stepID] = message
	return last == message
}

func formatCLILogMessage(p domain.LogPayload) string {
	msg := strings.TrimSpace(p.Message)
	if p.Fields != nil && p.Fields["kind"] == "inline_progress" {
		label := strings.TrimSpace(p.Fields["label"])
		if label == "" {
			label = msg
		}
		pct := strings.TrimSpace(p.Fields["pct"])
		tail := strings.TrimSpace(p.Fields["tail"])
		if pct != "" {
			msg = fmt.Sprintf("%s %s%%", label, pct)
		} else {
			msg = label
		}
		if tail != "" {
			msg += " " + tail
		}
	}
	return msg
}

func shouldPrintQuiet(message string) bool {
	msg := strings.TrimSpace(message)
	if msg == "" {
		return false
	}
	if isIssueMessage(msg) {
		return true
	}
	if isVerboseMessage(msg) {
		return false
	}
	return true
}

func isVerboseMessage(message string) bool {
	for _, prefix := range []string{
		"- Downloading ",
		"- Installing ",
		"- Syncing ",
		"- Cloning ",
	} {
		if strings.HasPrefix(message, prefix) {
			return true
		}
	}
	switch {
	case strings.HasPrefix(message, "Package operations:"):
		return true
	case strings.HasPrefix(message, "Verifying lock file contents can be installed on current platform."):
		return true
	case strings.HasPrefix(message, "Generating optimized autoload files"):
		return true
	case strings.HasPrefix(message, "Extracting"):
		return true
	case strings.HasPrefix(message, "Created project in "):
		return true
	case strings.HasPrefix(message, "Use the `composer fund` command"):
		return true
	case strings.Contains(message, "packages you are using are looking for funding"):
		return true
	}
	return false
}

func isIssueMessage(message string) bool {
	lower := strings.ToLower(message)
	if strings.Contains(lower, "warning") || strings.Contains(lower, "error") || strings.Contains(lower, "failed") {
		return true
	}
	if strings.Contains(message, "⚠") || strings.Contains(message, "✗") {
		return true
	}
	return false
}

func stepLabel(stepLabels map[string]string, stepID string, payload any) string {
	if p, ok := payload.(domain.StepStartPayload); ok {
		if label := strings.TrimSpace(p.Label); label != "" {
			stepLabels[stepID] = label
			return label
		}
	}
	if label := strings.TrimSpace(stepLabels[stepID]); label != "" {
		return label
	}
	return stepID
}
