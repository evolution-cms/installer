package logging

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
)

type Config struct {
	Always         bool
	InstallDir     string
	Version        string
	Mode           string
	Force          bool
	Branch         string
	DBType         string
	DBHost         string
	DBPort         int
	DBName         string
	AdminDirectory string
	Language       string
}

type Result struct {
	Path    string
	Written bool
}

type EventLogger struct {
	cfg         Config
	started     time.Time
	ended       time.Time
	stepLabels  map[string]string
	buffer      logBuffer
	hadError    bool
	failedSteps map[string]bool
}

func NewEventLogger(cfg Config) *EventLogger {
	return &EventLogger{
		cfg:         cfg,
		started:     time.Now(),
		stepLabels:  map[string]string{},
		failedSteps: map[string]bool{},
	}
}

func (l *EventLogger) Record(ev domain.Event) {
	if ev.TS.IsZero() {
		ev.TS = time.Now()
	}
	l.ended = ev.TS

	switch ev.Type {
	case domain.EventSteps:
		switch p := ev.Payload.(type) {
		case domain.StepsPayload:
			for _, s := range p.Steps {
				if s.ID != "" && s.Label != "" {
					l.stepLabels[s.ID] = s.Label
				}
			}
		case []domain.StepState:
			for _, s := range p {
				if s.ID != "" && s.Label != "" {
					l.stepLabels[s.ID] = s.Label
				}
			}
		}
	case domain.EventStepStart:
		label := ev.StepID
		if p, ok := ev.Payload.(domain.StepStartPayload); ok && strings.TrimSpace(p.Label) != "" {
			label = strings.TrimSpace(p.Label)
		}
		if ev.StepID != "" && label != "" {
			l.stepLabels[ev.StepID] = label
		}
		l.buffer.append(domain.LogEntry{
			TS:      ev.TS,
			Level:   domain.LogInfo,
			Source:  ev.Source,
			StepID:  ev.StepID,
			Message: "Step started: " + label,
		})
	case domain.EventStepDone:
		ok := true
		if p, okPayload := ev.Payload.(domain.StepDonePayload); okPayload {
			ok = p.OK
		}
		if !ok && ev.StepID != "" {
			l.failedSteps[ev.StepID] = true
		}
		label := l.stepLabels[ev.StepID]
		if strings.TrimSpace(label) == "" {
			label = ev.StepID
		}
		level := domain.LogInfo
		msg := "Step completed: " + label
		if !ok {
			level = domain.LogWarning
			msg = "Step failed: " + label
		}
		l.buffer.append(domain.LogEntry{
			TS:      ev.TS,
			Level:   level,
			Source:  ev.Source,
			StepID:  ev.StepID,
			Message: msg,
		})
	case domain.EventProgress:
		if p, ok := ev.Payload.(domain.ProgressPayload); ok {
			unit := strings.TrimSpace(p.Unit)
			if unit == "" {
				unit = "units"
			}
			l.buffer.append(domain.LogEntry{
				TS:      ev.TS,
				Level:   domain.LogInfo,
				Source:  ev.Source,
				StepID:  ev.StepID,
				Message: fmt.Sprintf("Progress: %d/%d %s", p.Current, p.Total, unit),
			})
		}
	case domain.EventLog:
		if _, ok := ev.Payload.(domain.LogPayload); ok {
			l.buffer.add(ev, domain.LogInfo)
		}
	case domain.EventWarning:
		if _, ok := ev.Payload.(domain.LogPayload); ok {
			l.buffer.add(ev, domain.LogWarning)
		}
	case domain.EventError:
		l.hadError = true
		if !isGlobalFailure(ev) && ev.StepID != "" {
			l.failedSteps[ev.StepID] = true
		}
		if _, ok := ev.Payload.(domain.LogPayload); ok {
			if isGlobalFailure(ev) {
				evCopy := ev
				evCopy.StepID = ""
				l.buffer.add(evCopy, domain.LogError)
			} else {
				l.buffer.add(ev, domain.LogError)
			}
		}
	}
}

func (l *EventLogger) MarkFailure() {
	l.hadError = true
}

func (l *EventLogger) Finalize() (Result, error) {
	if !l.cfg.Always && !l.hadError {
		return Result{}, nil
	}

	logDir := resolveLogDir(l.cfg.InstallDir)
	path := filepath.Join(logDir, "log.md")

	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		return Result{}, err
	}
	defer f.Close()

	w := bufio.NewWriter(f)
	if l.ended.IsZero() {
		l.ended = time.Now()
	}
	writeMarkdown(w, l.cfg, l.started, l.ended, l.hadError, l.failedSteps, l.stepLabels, l.buffer.entries)
	if err := w.Flush(); err != nil {
		return Result{}, err
	}
	return Result{Path: path, Written: true}, nil
}

type stepGroup struct {
	stepID  string
	label   string
	entries []domain.LogEntry
}

type logItem struct {
	ts      time.Time
	level   domain.LogLevel
	source  string
	message string
	fields  string
	count   int
}

func formatMessage(e domain.LogEntry) string {
	msg := strings.TrimSpace(e.Message)
	if e.Fields != nil && e.Fields["kind"] == "inline_progress" {
		label := strings.TrimSpace(e.Fields["label"])
		if label == "" {
			label = msg
		}
		pct := strings.TrimSpace(e.Fields["pct"])
		tail := strings.TrimSpace(e.Fields["tail"])
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

func writeMarkdown(w *bufio.Writer, cfg Config, started time.Time, ended time.Time, hadError bool, failedSteps map[string]bool, stepLabels map[string]string, entries []domain.LogEntry) {
	stepGroups, general := groupEntries(stepLabels, entries)
	result := "Completed"
	if hadError {
		result = "Failed"
	}
	failedList := formatFailedSteps(stepGroups, failedSteps)

	fmt.Fprintln(w, "# Evolution CMS Installer log")
	fmt.Fprintln(w)
	fmt.Fprintln(w, "## Summary")
	fmt.Fprintf(w, "- Started: %s\n", started.Format(time.RFC3339))
	fmt.Fprintf(w, "- Ended: %s\n", ended.Format(time.RFC3339))
	fmt.Fprintf(w, "- Result: %s\n", result)
	if hadError {
		if reason := failureReason(entries); reason != "" {
			fmt.Fprintf(w, "- Failure reason: %s\n", reason)
		}
	}
	if len(failedList) > 0 {
		fmt.Fprintf(w, "- Failed steps: %s\n", strings.Join(failedList, "; "))
	}
	if strings.TrimSpace(cfg.InstallDir) != "" {
		fmt.Fprintf(w, "- Install dir: %s\n", strings.TrimSpace(cfg.InstallDir))
	}
	if strings.TrimSpace(cfg.Version) != "" {
		fmt.Fprintf(w, "- Version: %s\n", strings.TrimSpace(cfg.Version))
	}
	if strings.TrimSpace(cfg.Mode) != "" {
		fmt.Fprintf(w, "- Mode: %s\n", strings.TrimSpace(cfg.Mode))
	}
	if opts := formatOptions(cfg); opts != "" {
		fmt.Fprintf(w, "- Options: %s\n", opts)
	}
	fmt.Fprintln(w)
	fmt.Fprintln(w, "## Steps")
	if len(stepGroups) == 0 {
		fmt.Fprintln(w, "_No step logs recorded._")
	} else {
		for _, g := range stepGroups {
			writeStepSection(w, g)
		}
	}
	if len(general) > 0 {
		fmt.Fprintln(w)
		fmt.Fprintln(w, "## General")
		writeEntries(w, general)
	}
}

func groupEntries(stepLabels map[string]string, entries []domain.LogEntry) ([]stepGroup, []domain.LogEntry) {
	seen := map[string]*stepGroup{}
	order := []string{}
	var general []domain.LogEntry

	for _, entry := range entries {
		stepID := strings.TrimSpace(entry.StepID)
		if stepID == "" {
			general = append(general, entry)
			continue
		}
		group, ok := seen[stepID]
		if !ok {
			label := strings.TrimSpace(stepLabels[stepID])
			group = &stepGroup{stepID: stepID, label: label}
			seen[stepID] = group
			order = append(order, stepID)
		}
		group.entries = append(group.entries, entry)
	}

	out := make([]stepGroup, 0, len(order))
	for _, id := range order {
		group := seen[id]
		if group != nil {
			out = append(out, *group)
		}
	}
	return out, general
}

func writeStepSection(w *bufio.Writer, g stepGroup) {
	label := formatStepHeading(g.label, g.stepID)
	fmt.Fprintln(w, label)
	writeEntries(w, g.entries)
	fmt.Fprintln(w)
}

func writeEntries(w *bufio.Writer, entries []domain.LogEntry) {
	items := compressEntries(entries)
	if len(items) == 0 {
		fmt.Fprintln(w, "_No logs recorded._")
		return
	}

	var highlights []logItem
	var issues []logItem
	hasVerbose := false

	for _, item := range items {
		if isIssueItem(item) {
			issues = append(issues, item)
		}
		if isVerboseMessage(item.message) {
			hasVerbose = true
		}
		if !isVerboseMessage(item.message) || isIssueItem(item) {
			highlights = append(highlights, item)
		}
	}

	if len(issues) > 0 {
		fmt.Fprintln(w, "#### Issues")
		fmt.Fprintln(w, "```text")
		for _, item := range issues {
			fmt.Fprintln(w, formatItemLine(item, true))
		}
		fmt.Fprintln(w, "```")
		fmt.Fprintln(w)
	}

	if len(highlights) > 0 {
		fmt.Fprintln(w, "#### Highlights")
		for _, item := range highlights {
			fmt.Fprintf(w, "- %s\n", formatItemLine(item, false))
		}
		fmt.Fprintln(w)
	}

	if hasVerbose {
		fmt.Fprintln(w, "<details>")
		fmt.Fprintf(w, "<summary>Full output (%d lines)</summary>\n\n", len(items))
		fmt.Fprintln(w, "```text")
		for _, item := range items {
			fmt.Fprintln(w, formatItemLine(item, true))
		}
		fmt.Fprintln(w, "```")
		fmt.Fprintln(w, "</details>")
	}
}

func compressEntries(entries []domain.LogEntry) []logItem {
	items := make([]logItem, 0, len(entries))
	for _, entry := range entries {
		msg := sanitizeMessage(formatMessage(entry))
		fields := formatFields(entry.Fields)
		item := logItem{
			ts:      entry.TS,
			level:   entry.Level,
			source:  strings.TrimSpace(entry.Source),
			message: msg,
			fields:  fields,
			count:   1,
		}
		if len(items) > 0 {
			last := &items[len(items)-1]
			if last.level == item.level && last.source == item.source && last.message == item.message && last.fields == item.fields {
				last.count++
				continue
			}
		}
		items = append(items, item)
	}
	return items
}

func formatItemLine(item logItem, includeFields bool) string {
	ts := item.ts
	if ts.IsZero() {
		ts = time.Now()
	}
	level := strings.ToUpper(string(item.level))
	if level == "" {
		level = "INFO"
	}
	line := fmt.Sprintf("%s [%s]", ts.Format("2006-01-02 15:04:05"), level)
	if item.source != "" {
		line += " (" + item.source + ")"
	}
	if item.message != "" {
		line += " " + item.message
	}
	if item.count > 1 {
		line += fmt.Sprintf(" (x%d)", item.count)
	}
	if includeFields && item.fields != "" {
		line += " [" + item.fields + "]"
	}
	return line
}

func isVerboseMessage(message string) bool {
	msg := strings.TrimSpace(message)
	if msg == "" {
		return false
	}
	for _, prefix := range []string{
		"- Downloading ",
		"- Installing ",
		"- Syncing ",
		"- Cloning ",
	} {
		if strings.HasPrefix(msg, prefix) {
			return true
		}
	}
	if strings.HasPrefix(msg, "Package operations:") {
		return true
	}
	if strings.HasPrefix(msg, "Generating optimized autoload files") {
		return true
	}
	return false
}

func isIssueItem(item logItem) bool {
	if item.level == domain.LogError || item.level == domain.LogWarning {
		return true
	}
	msg := strings.ToLower(item.message)
	if strings.Contains(msg, "✗") || strings.Contains(msg, "⚠") {
		return true
	}
	if strings.Contains(msg, "failed") || strings.Contains(msg, "error") || strings.Contains(msg, "warning") {
		return true
	}
	return false
}

func isGlobalFailure(ev domain.Event) bool {
	if ev.StepID != "download" {
		return false
	}
	payload, ok := ev.Payload.(domain.LogPayload)
	if !ok {
		return false
	}
	msg := strings.TrimSpace(payload.Message)
	return strings.EqualFold(msg, "Installation failed.") || strings.EqualFold(msg, "Installation failed")
}

func formatStepHeading(label, stepID string) string {
	label = strings.TrimSpace(label)
	stepID = strings.TrimSpace(stepID)
	if label == "" {
		label = stepID
	}
	if label == "" {
		label = "Unknown step"
	}
	if stepID == "" {
		return "### " + label
	}
	return fmt.Sprintf("### %s (`%s`)", label, stepID)
}

func formatFailedSteps(groups []stepGroup, failedSteps map[string]bool) []string {
	if len(failedSteps) == 0 {
		return nil
	}
	out := []string{}
	seen := map[string]bool{}
	for _, g := range groups {
		if !failedSteps[g.stepID] {
			continue
		}
		seen[g.stepID] = true
		out = append(out, formatStepRef(g.label, g.stepID))
	}
	for stepID := range failedSteps {
		if seen[stepID] {
			continue
		}
		out = append(out, formatStepRef("", stepID))
	}
	return out
}

func formatStepRef(label, stepID string) string {
	label = strings.TrimSpace(label)
	stepID = strings.TrimSpace(stepID)
	if label == "" && stepID == "" {
		return ""
	}
	if label == "" {
		return "`" + stepID + "`"
	}
	if stepID == "" {
		return label
	}
	return fmt.Sprintf("%s (`%s`)", label, stepID)
}

func formatOptions(cfg Config) string {
	opts := []string{}
	if cfg.Force {
		opts = append(opts, "force=true")
	}
	if strings.TrimSpace(cfg.Branch) != "" {
		opts = append(opts, "branch="+strings.TrimSpace(cfg.Branch))
	}
	if strings.TrimSpace(cfg.DBType) != "" {
		opts = append(opts, "db-type="+strings.TrimSpace(cfg.DBType))
	}
	if strings.TrimSpace(cfg.DBHost) != "" {
		opts = append(opts, "db-host="+strings.TrimSpace(cfg.DBHost))
	}
	if cfg.DBPort > 0 {
		opts = append(opts, fmt.Sprintf("db-port=%d", cfg.DBPort))
	}
	if strings.TrimSpace(cfg.DBName) != "" {
		opts = append(opts, "db-name="+strings.TrimSpace(cfg.DBName))
	}
	if strings.TrimSpace(cfg.AdminDirectory) != "" {
		opts = append(opts, "admin-directory="+strings.TrimSpace(cfg.AdminDirectory))
	}
	if strings.TrimSpace(cfg.Language) != "" {
		opts = append(opts, "language="+strings.TrimSpace(cfg.Language))
	}
	return strings.Join(opts, ", ")
}

func failureReason(entries []domain.LogEntry) string {
	for _, entry := range entries {
		if entry.Level != domain.LogError {
			continue
		}
		if entry.Fields != nil {
			if errText := strings.TrimSpace(entry.Fields["error"]); errText != "" {
				return sanitizeMessage(errText)
			}
		}
		if msg := sanitizeMessage(formatMessage(entry)); msg != "" {
			return msg
		}
	}

	for _, entry := range entries {
		if entry.Level != domain.LogWarning {
			continue
		}
		if entry.Fields != nil {
			if errText := strings.TrimSpace(entry.Fields["error"]); errText != "" {
				return sanitizeMessage(errText)
			}
		}
		if msg := sanitizeMessage(formatMessage(entry)); msg != "" && isFailureMessage(msg) {
			return msg
		}
	}

	for _, entry := range entries {
		msg := sanitizeMessage(formatMessage(entry))
		if msg != "" && isFailureMessage(msg) {
			return msg
		}
	}
	return ""
}

func isFailureMessage(message string) bool {
	msg := strings.ToLower(strings.TrimSpace(message))
	if msg == "" {
		return false
	}
	if strings.HasPrefix(msg, "✗") || strings.HasPrefix(msg, "⚠") {
		return true
	}
	if strings.Contains(msg, "failed") || strings.Contains(msg, "error") {
		return true
	}
	return false
}

func formatFields(fields map[string]string) string {
	if len(fields) == 0 {
		return ""
	}
	keys := make([]string, 0, len(fields))
	for k := range fields {
		if isInternalField(k) {
			continue
		}
		keys = append(keys, k)
	}
	if len(keys) == 0 {
		return ""
	}
	sort.Strings(keys)
	out := make([]string, 0, len(keys))
	for _, k := range keys {
		v := fields[k]
		if isSensitiveKey(k) {
			v = "<redacted>"
		}
		v = sanitizeMessage(v)
		out = append(out, k+"="+formatValue(v))
	}
	return strings.Join(out, " ")
}

func formatValue(v string) string {
	v = strings.TrimSpace(v)
	v = strings.ReplaceAll(v, "\n", " ")
	v = strings.ReplaceAll(v, "\r", " ")
	if v == "" {
		return "\"\""
	}
	if strings.ContainsAny(v, " \t") {
		return strconv.Quote(v)
	}
	return v
}

func resolveLogDir(installDir string) string {
	dir := strings.TrimSpace(installDir)
	if dir == "" {
		dir = "."
	}
	dir = filepath.Clean(dir)
	if err := os.MkdirAll(dir, 0o755); err == nil {
		return dir
	}
	if cwd, err := os.Getwd(); err == nil {
		if err := os.MkdirAll(cwd, 0o755); err == nil {
			return cwd
		}
	}
	return os.TempDir()
}

func isInternalField(k string) bool {
	switch strings.ToLower(strings.TrimSpace(k)) {
	case "op", "kind", "progress_key", "label", "pct", "tail":
		return true
	default:
		return false
	}
}

func isSensitiveKey(k string) bool {
	k = strings.ToLower(strings.TrimSpace(k))
	return strings.Contains(k, "password") || strings.Contains(k, "email") || strings.Contains(k, "user") || strings.Contains(k, "login")
}

type logBuffer struct {
	entries []domain.LogEntry
}

func (b *logBuffer) append(entry domain.LogEntry) {
	b.entries = append(b.entries, entry)
}

func (b *logBuffer) add(ev domain.Event, level domain.LogLevel) {
	payload, ok := ev.Payload.(domain.LogPayload)
	if !ok {
		return
	}

	if payload.Fields != nil {
		switch payload.Fields["op"] {
		case "replace_last":
			if len(b.entries) > 0 {
				last := &b.entries[len(b.entries)-1]
				last.TS = ev.TS
				last.Level = level
				last.Source = ev.Source
				last.StepID = ev.StepID
				last.Message = payload.Message
				last.Fields = payload.Fields
				return
			}
		case "replace_last_if_same":
			if len(b.entries) > 0 {
				key := payload.Fields["progress_key"]
				last := b.entries[len(b.entries)-1]
				if last.Fields != nil && last.Fields["kind"] == payload.Fields["kind"] && last.Fields["progress_key"] == key {
					b.entries[len(b.entries)-1] = domain.LogEntry{
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

	b.entries = append(b.entries, domain.LogEntry{
		TS:      ev.TS,
		Level:   level,
		Source:  ev.Source,
		StepID:  ev.StepID,
		Message: payload.Message,
		Fields:  payload.Fields,
	})
}

var sensitivePrefixReplacements = []string{
	"selected database user:",
	"selected database password:",
	"your admin username:",
	"your admin email:",
	"your admin password:",
	"admin username:",
	"admin email:",
	"username:",
	"password:",
}

var (
	flagValueRe   = regexp.MustCompile(`(?i)(--(?:db-user|db-password|admin-username|admin-email|admin-password))=\S+`)
	emailRe       = regexp.MustCompile(`(?i)[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}`)
	userQuotedRe  = regexp.MustCompile(`(?i)\b(user|username|login)\s*'[^']*'`)
	userDQuotedRe = regexp.MustCompile(`(?i)\b(user|username|login)\s*\"[^\"]*\"`)
	kvRedactRe    = regexp.MustCompile(`(?i)\b(username|login|email|password)\s*[:=]\s*\S+`)
)

func sanitizeMessage(message string) string {
	if strings.TrimSpace(message) == "" {
		return ""
	}

	message = stripControlChars(message)
	message = redactByPrefix(message)
	message = flagValueRe.ReplaceAllString(message, "$1=<redacted>")
	message = emailRe.ReplaceAllString(message, "<redacted>")
	message = userQuotedRe.ReplaceAllString(message, "$1 '<redacted>'")
	message = userDQuotedRe.ReplaceAllString(message, "$1 \"<redacted>\"")
	message = kvRedactRe.ReplaceAllString(message, "$1: <redacted>")
	return message
}

func stripControlChars(message string) string {
	return strings.Map(func(r rune) rune {
		if r == '\n' || r == '\t' || r == '\r' {
			return ' '
		}
		if r < 32 || r == 127 {
			return -1
		}
		return r
	}, message)
}

func redactByPrefix(message string) string {
	leading := message[:len(message)-len(strings.TrimLeft(message, " \t"))]
	trimmed := strings.TrimLeft(message, " \t")
	lower := strings.ToLower(trimmed)
	for _, prefix := range sensitivePrefixReplacements {
		if strings.HasPrefix(lower, prefix) {
			origPrefix := trimmed[:len(prefix)]
			return leading + origPrefix + " <redacted>."
		}
	}
	return message
}
