package ui

import (
	"fmt"
	"regexp"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/charmbracelet/lipgloss"
	"github.com/charmbracelet/x/ansi"
	"github.com/mattn/go-runewidth"
	reflowtruncate "github.com/muesli/reflow/truncate"

	"github.com/evolution-cms/installer/internal/domain"
)

func (m *Model) View() string {
	if m.width <= 0 || m.height <= 0 {
		return "Loading…"
	}

	usableH := max(0, m.height-1) // reserve 1 row for the global footer hints
	footer := footerHints(m.width)
	if usableH == 0 {
		return footer
	}

	var body string

	switch {
	case m.cancelling && m.engineDone:
		body = lipgloss.Place(m.width, usableH, lipgloss.Center, lipgloss.Center, "Installation cancelled.")
	case m.state.Release.Loading || m.systemStatusLoading:
		if m.cancelling {
			line1 := m.spin.View() + " Cancelling…"
			line2 := mutedStyle.Render("Stopping installer…")
			body = lipgloss.Place(m.width, usableH, lipgloss.Center, lipgloss.Center, line1+"\n"+line2)
			break
		}
		msg := "Fetching latest version…"
		if !m.state.Release.Loading && m.systemStatusLoading {
			msg = "Checking system status…"
		}
		line1 := m.spin.View() + " " + msg
		line2 := mutedStyle.Render("Starting installer…")
		body = lipgloss.Place(m.width, usableH, lipgloss.Center, lipgloss.Center, line1+"\n"+line2)
	default:
		if m.layout.width != m.width || m.layout.height != usableH {
			m.reflow()
		}
		if m.layout.tooSmall {
			body = minSizeView(m.width, usableH)
			break
		}

		header := m.renderHeader(m.layout.leftW, m.layout.showLogo)
		quest := panel("Quest track", m.questVP.View(), m.layout.leftW, m.layout.questH)

		leftTop := lipgloss.JoinVertical(lipgloss.Top, header, quest)

		status := panel("System status", m.statusVP.View(), m.layout.rightW, m.layout.topAreaH)

		top := lipgloss.JoinHorizontal(lipgloss.Top,
			leftTop,
			strings.Repeat(" ", m.layout.gap),
			status,
		)

		logs := panel("Log", m.renderLogPanelBody(panelContentWidth(m.layout.width), panelBodyHeight(m.layout.logH, true)), m.layout.width, m.layout.logH)

		body = lipgloss.JoinVertical(lipgloss.Top, top, logs)
	}

	out := lipgloss.JoinVertical(lipgloss.Top, body, footer)
	if m.confirmQuitActive {
		modal := m.confirmQuitModal(usableH)
		x := max(0, (m.width-lipgloss.Width(modal))/2)
		y := max(0, (usableH-len(splitLines(modal)))/2)
		return overlayAt(out, m.width, m.height, modal, x, y)
	}
	return out
}

func keyHintsLine() string {
	return "↑/↓ Navigate/Scroll  PgUp/PgDn Scroll  End Follow  Enter Select  ctrl+c Cancel  ctrl+q Quit"
}

func footerHints(width int) string {
	hint := mutedStyle.Render(truncatePlain(keyHintsLine(), width))
	return lipgloss.Place(width, 1, lipgloss.Center, lipgloss.Center, hint)
}

func (m *Model) confirmQuitModal(height int) string {
	w := m.width
	if w <= 0 || height <= 0 {
		return ""
	}

	padX := 2
	padY := 1
	if w < 20 {
		padX = 1
	}
	if height < 10 {
		padY = 0
	}

	availW := max(0, w-2*padX)
	availH := max(0, height-2*padY)

	boxW := 66
	if availW < boxW {
		boxW = max(30, availW-4)
	}
	boxH := 9
	if availH < boxH {
		boxH = max(7, availH)
	}

	msg := "Do you really want to abort installation?"
	optAbort := " Abort "
	optStay := " Continue "

	abortStyle := errStyle.Copy().Bold(true)
	stayStyle := okStyle.Copy().Bold(true)
	unselected := mutedStyle.Copy()

	abort := "[" + optAbort + "]"
	stay := "[" + optStay + "]"
	if m.confirmQuitSelected == 0 {
		abort = abortStyle.Render(abort)
		stay = unselected.Render(stay)
	} else {
		abort = unselected.Render(abort)
		stay = stayStyle.Render(stay)
	}

	options := abort + "   " + stay
	hint := mutedStyle.Render("Enter: select   Esc/Ctrl+Q: close")

	content := truncatePlain(msg, panelContentWidth(boxW))
	content = content + "\n\n" + truncateANSI(options, panelContentWidth(boxW)) + "\n\n" + truncateANSI(hint, panelContentWidth(boxW))
	p := panel("Confirm exit", content, boxW, boxH)
	if padX == 0 && padY == 0 {
		return p
	}
	return lipgloss.NewStyle().Padding(padY, padX).Render(p)
}

func overlayAt(base string, baseW int, baseH int, overlay string, x int, y int) string {
	if baseW <= 0 || baseH <= 0 {
		return base
	}
	if strings.TrimSpace(overlay) == "" {
		return base
	}

	baseLines := splitLines(base)
	if len(baseLines) > baseH {
		baseLines = baseLines[:baseH]
	}
	for len(baseLines) < baseH {
		baseLines = append(baseLines, "")
	}
	for i := range baseLines {
		baseLines[i] = padRight(truncateANSI(baseLines[i], baseW), baseW)
	}

	overlayLines := splitLines(overlay)
	overlayW := lipgloss.Width(overlay)
	if overlayW <= 0 {
		overlayW = 1
	}
	overlayH := len(overlayLines)

	if x < 0 {
		x = 0
	}
	if y < 0 {
		y = 0
	}
	if x >= baseW || y >= baseH {
		return strings.Join(baseLines, "\n")
	}

	for i := 0; i < overlayH; i++ {
		row := y + i
		if row < 0 || row >= baseH {
			continue
		}
		oline := overlayLines[i]
		oline = padRight(truncateANSI(oline, overlayW), overlayW)

		left := ansi.Cut(baseLines[row], 0, x)
		right := ansi.Cut(baseLines[row], x+overlayW, baseW)
		baseLines[row] = left + oline + right
	}

	return strings.Join(baseLines, "\n")
}

func (m *Model) renderHeader(width int, showLogo bool) string {
	if showLogo {
		return m.renderLogoHeader(width)
	}
	return m.renderCompactHeader(width)
}

func (m *Model) renderLogoHeader(width int) string {
	innerW := panelContentWidth(width)
	innerH := panelBodyHeight(logoHeaderHeight, true)

	logoLines := splitLines(strings.TrimRight(logoText, "\n"))
	logoH := len(logoLines)
	if logoH == 0 {
		return m.renderCompactHeader(width)
	}
	if logoH > innerH {
		// If the logo doesn't fit the fixed header height, fall back rather than clipping it.
		return m.renderCompactHeader(width)
	}

	maxLogoW := 0
	for _, l := range logoLines {
		if w := lipgloss.Width(l); w > maxLogoW {
			maxLogoW = w
		}
	}

	const gap = 2
	availableMetaW := innerW - maxLogoW - gap
	if availableMetaW < 1 {
		return m.renderCompactHeader(width)
	}

	versionText, versionLineStyle := m.releaseVersionLine()
	tagline := m.meta.Tagline
	if tagline == "" {
		tagline = "The world’s fastest CMS!"
	}
	version := versionLineStyle.Render(cutPlain(versionText, availableMetaW))
	tagline = taglineStyle.Render(cutPlain(tagline, availableMetaW))

	metaBase := []string{version, tagline}
	metaH := len(metaBase)
	metaTopPad := max(0, (logoH-metaH)/2)
	metaBottomPad := max(0, logoH-metaH-metaTopPad)

	metaLines := make([]string, 0, logoH)
	for i := 0; i < metaTopPad; i++ {
		metaLines = append(metaLines, "")
	}
	metaLines = append(metaLines, metaBase...)
	for i := 0; i < metaBottomPad; i++ {
		metaLines = append(metaLines, "")
	}
	for len(metaLines) < logoH {
		metaLines = append(metaLines, "")
	}
	if len(metaLines) > logoH {
		metaLines = metaLines[:logoH]
	}

	combinedLines := make([]string, 0, logoH)
	blockW := 0
	for i := 0; i < logoH; i++ {
		left := logoStyle.Render(logoLines[i])
		right := metaLines[i]
		line := strings.TrimRight(left+strings.Repeat(" ", gap)+right, " ")
		combinedLines = append(combinedLines, line)
		if w := lipgloss.Width(line); w > blockW {
			blockW = w
		}
	}

	// Horizontal centering of the whole block.
	if blockW > innerW {
		return m.renderCompactHeader(width)
	}
	leftPad := max(0, (innerW-blockW)/2)
	rightPad := max(0, innerW-blockW-leftPad)

	paddedLines := make([]string, 0, innerH)
	for _, line := range combinedLines {
		line = cutANSI(line, blockW)
		padded := strings.Repeat(" ", leftPad) + padRight(line, blockW) + strings.Repeat(" ", rightPad)
		paddedLines = append(paddedLines, padded)
	}

	// Vertical centering inside the header box.
	contentH := len(paddedLines)
	topPad := max(0, (innerH-contentH)/2)
	bottomPad := max(0, innerH-contentH-topPad)

	outLines := make([]string, 0, innerH)
	for i := 0; i < topPad; i++ {
		outLines = append(outLines, "")
	}
	outLines = append(outLines, paddedLines...)
	for i := 0; i < bottomPad; i++ {
		outLines = append(outLines, "")
	}
	if len(outLines) > innerH {
		outLines = outLines[:innerH]
	}

	return panel("Evolution CMS Installer", strings.Join(outLines, "\n"), width, logoHeaderHeight)
}

func (m *Model) renderCompactHeader(width int) string {
	contentW := panelContentWidth(width)
	versionText, versionLineStyle := m.releaseVersionLine()
	version := versionLineStyle.Render(cutPlain(versionText, contentW))
	return panel("Evolution CMS Installer", version, width, compactHeaderHeight)
}

func (m *Model) releaseVersionLine() (string, lipgloss.Style) {
	if m.state.Release.Loading {
		return "Fetching version…", mutedStyle
	}
	if m.state.Release.Error != "" {
		return "v—", mutedStyle
	}
	if m.state.Release.Highest.HighestVersion != "" {
		return "v" + m.state.Release.Highest.HighestVersion, versionStyle
	}
	return "v—", mutedStyle
}

func formatVersion(v string) string {
	v = strings.TrimSpace(v)
	if v == "" {
		v = "dev"
	}
	if strings.HasPrefix(strings.ToLower(v), "v") {
		return v
	}
	if v[0] >= '0' && v[0] <= '9' {
		return "v" + v
	}
	return v
}

func (m *Model) renderSteps(width int) string {
	lines := make([]string, 0, len(m.state.Steps))
	for _, s := range m.state.Steps {
		icon, iconStyle, labelStyle := stepMarker(s.Status)
		avail := max(0, width-2)
		label := truncatePlain(s.Label, avail)
		line := iconStyle.Render(icon) + " " + labelStyle.Render(label)
		lines = append(lines, truncateANSI(line, width))
	}
	if len(lines) == 0 {
		return truncatePlain("(no steps)", width)
	}
	return strings.Join(lines, "\n")
}

func (m *Model) renderSystem(width int) string {
	if len(m.state.SystemStatus.Items) == 0 {
		return truncatePlain("(no checks yet)", width)
	}
	lines := make([]string, 0, len(m.state.SystemStatus.Items))
	for _, it := range m.state.SystemStatus.Items {
		ind, indStyle := statusIndicator(it.Level)
		label := it.Label
		avail := max(0, width-2)
		line := indStyle.Render(ind) + " " + truncatePlain(label, avail)
		lines = append(lines, truncateANSI(line, width))
	}
	return strings.Join(lines, "\n")
}

func (m *Model) renderLogStream(width int) string {
	entries := m.state.Logs.Entries
	lines := make([]string, 0, len(entries))

	activeStepID := ""
	for _, s := range m.state.Steps {
		if s.Status == domain.StepActive {
			activeStepID = s.ID
			break
		}
	}

	pulseIdx := -1
	if activeStepID != "" && len(entries) > 0 && !m.engineDone && !m.cancelling {
		for i := len(entries) - 1; i >= 0; i-- {
			if entries[i].StepID == activeStepID {
				pulseIdx = i
				break
			}
		}
		if pulseIdx == -1 {
			pulseIdx = len(entries) - 1
		}
	}

	pulseA := taglineStyle.Copy().Bold(true)
	pulseB := versionStyle.Copy().Bold(true)

	for i, e := range entries {
		ts := e.TS
		if ts.IsZero() {
			ts = time.Now()
		}

		prefix, pStyle := logPrefix(e.Level)
		if i == pulseIdx && e.Level == domain.LogInfo {
			prefix = "•"
			if m.pulseOn {
				pStyle = pulseB
			} else {
				pStyle = pulseA
			}
		}

		timeStr := ts.Format("15:04:05")
		avail := max(0, width-lipgloss.Width(timeStr)-1-2) // "time␠<icon>␠"
		msg := truncateANSI(m.renderLogMessage(e, avail), avail)
		line := timeStr + " " + pStyle.Render(prefix) + " " + msg
		lines = append(lines, truncateANSI(line, width))
	}

	if len(lines) == 0 {
		return truncatePlain("(no logs yet)", width)
	}
	return strings.Join(lines, "\n")
}

func (m *Model) renderLogMessage(e domain.LogEntry, width int) string {
	if e.Fields != nil && e.Fields["kind"] == "inline_progress" {
		label := e.Fields["label"]
		if label == "" {
			label = e.Message
		}
		pct := 0
		_, _ = fmt.Sscanf(strings.TrimSpace(e.Fields["pct"]), "%d", &pct)
		if pct < 0 {
			pct = 0
		}
		if pct > 100 {
			pct = 100
		}
		tail := strings.TrimSpace(e.Fields["tail"])

		percent := float64(pct) / 100.0
		pctText := fmt.Sprintf("%3d%%", pct)

		// Compute bar width. Keep at least 5 cells for the bar to avoid noise.
		staticW := lipgloss.Width(label) + 1 + 1 + lipgloss.Width(pctText)
		if tail != "" {
			staticW += 1 + lipgloss.Width(tail)
		}
		barW := width - staticW
		if barW < 5 {
			// Drop tail first, then shrink label.
			tail = ""
			staticW = lipgloss.Width(label) + 1 + 1 + lipgloss.Width(pctText)
			barW = width - staticW
			if barW < 5 {
				label = truncatePlain(label, max(0, width-(1+1+lipgloss.Width(pctText)+5)))
				staticW = lipgloss.Width(label) + 1 + 1 + lipgloss.Width(pctText)
				barW = max(5, width-staticW)
			}
		}

		p := m.progress
		p.Width = barW
		bar := p.ViewAs(percent)

		msg := label + " " + bar + " " + pctText
		if tail != "" {
			msg += " " + mutedStyle.Render(truncatePlain(tail, max(0, width-lipgloss.Width(msg)-1)))
		}
		return msg
	}

	return highlightLogMessage(e.Message)
}

var logValuePrefixes = []string{
	"Highest stable release:",
	"Downloading Evolution CMS from branch:",
	"Selected database driver:",
	"Selected database host:",
	"Selected database name:",
	"Selected database user:",
	"Selected database password:",
	"Selected database path:",
	"Your Admin username:",
	"Your Admin email:",
	"Your Admin password:",
	"Your Admin directory:",
	"Selected language:",
}

func highlightLogMessage(message string) string {
	message = strings.TrimSpace(message)
	if message == "" {
		return ""
	}

	leadIcon, core := splitLeadingStatusIcon(message)

	core = highlightAdminSummary(core)
	core = highlightEvoVersionPhrases(core)

	// Highlight the PHP version in "PHP version X.Y.Z is supported."
	if strings.HasPrefix(core, "PHP version ") && strings.Contains(core, " is supported") {
		if v, ok := firstSemverLike(core); ok {
			core = strings.Replace(core, v, okStyle.Copy().Bold(true).Render(v), 1)
		}
	}

	for _, prefix := range logValuePrefixes {
		if !strings.HasPrefix(core, prefix) {
			continue
		}
		rest := strings.TrimSpace(strings.TrimPrefix(core, prefix))
		if rest == "" {
			return rejoinLeadingStatusIcon(leadIcon, core)
		}

		value, suffix := splitTrailingPunctuation(rest)
		if value == "" {
			return rejoinLeadingStatusIcon(leadIcon, core)
		}

		hi := okStyle.Copy().Bold(true).Render(value)
		core = prefix + " " + hi + suffix
		return rejoinLeadingStatusIcon(leadIcon, core)
	}

	core = highlightBranchPhrases(core)
	return rejoinLeadingStatusIcon(leadIcon, core)
}

var adminSummaryKVRe = regexp.MustCompile(`\b(Admin panel|Username|Password):\s*([^,]+)`)
var evoDownloadRe = regexp.MustCompile(`\b(Evolution CMS\s+[0-9]+\.[0-9]+\.[0-9]+)\b`)
var compatibleVersionRe = regexp.MustCompile(`\bcompatible version:\s*([vV]?[0-9]+\.[0-9]+\.[0-9]+)\b`)
var evoBranchRe = regexp.MustCompile(`\bbranch[: ]+([A-Za-z0-9._/\\-]+)\b`)

func highlightAdminSummary(s string) string {
	matches := adminSummaryKVRe.FindAllStringSubmatchIndex(s, -1)
	if matches == nil {
		return s
	}

	var b strings.Builder
	b.Grow(len(s) + 16)
	last := 0
	for _, m := range matches {
		// m: [fullStart fullEnd g1Start g1End g2Start g2End]
		if len(m) < 6 {
			continue
		}
		b.WriteString(s[last:m[0]])
		// Everything up to value start (includes "Key:" and any spaces).
		b.WriteString(s[m[0]:m[4]])

		rawVal := s[m[4]:m[5]]
		valCore := strings.TrimRight(rawVal, " ")
		valTrail := rawVal[len(valCore):]

		if valCore != "" {
			b.WriteString(versionStyle.Copy().Bold(true).Render(valCore))
		}
		b.WriteString(valTrail)
		last = m[5]
	}
	b.WriteString(s[last:])
	return b.String()
}

func highlightBranchPhrases(s string) string {
	matches := evoBranchRe.FindAllStringSubmatchIndex(s, -1)
	if matches == nil {
		return s
	}

	var b strings.Builder
	b.Grow(len(s) + 16)
	last := 0
	for _, m := range matches {
		// m: [fullStart fullEnd g1Start g1End]
		if len(m) < 4 {
			continue
		}
		b.WriteString(s[last:m[0]])
		b.WriteString(s[m[0]:m[2]])
		v := s[m[2]:m[3]]
		if v != "" {
			b.WriteString(okStyle.Copy().Bold(true).Render(v))
		}
		last = m[3]
	}
	b.WriteString(s[last:])
	return b.String()
}

func splitLeadingStatusIcon(s string) (icon string, rest string) {
	s = strings.TrimSpace(s)
	if s == "" {
		return "", ""
	}
	// Match: "<icon><space>..."
	// Keep this minimal and deterministic; we only style known icons.
	if strings.HasPrefix(s, "✔ ") {
		return "✔", strings.TrimSpace(strings.TrimPrefix(s, "✔ "))
	}
	if strings.HasPrefix(s, "✖ ") {
		return "✖", strings.TrimSpace(strings.TrimPrefix(s, "✖ "))
	}
	if strings.HasPrefix(s, "⚠ ") {
		return "⚠", strings.TrimSpace(strings.TrimPrefix(s, "⚠ "))
	}
	if strings.HasPrefix(s, "? ") {
		return "?", strings.TrimSpace(strings.TrimPrefix(s, "? "))
	}
	return "", s
}

func rejoinLeadingStatusIcon(icon string, core string) string {
	if icon == "" {
		return core
	}
	style := mutedStyle
	switch icon {
	case "✔":
		style = okStyle
	case "✖":
		style = errStyle
	case "⚠":
		style = warnStyle
	case "?":
		style = questionStyle
	}
	return style.Render(icon) + " " + core
}

func firstSemverLike(s string) (string, bool) {
	// Best-effort scan for X.Y.Z.
	rs := []rune(s)
	for i := 0; i < len(rs); i++ {
		if rs[i] < '0' || rs[i] > '9' {
			continue
		}
		start := i
		j := i
		dotCount := 0
		digitRun := 0
		for j < len(rs) {
			r := rs[j]
			if r >= '0' && r <= '9' {
				digitRun++
				j++
				continue
			}
			if r == '.' {
				if digitRun == 0 {
					break
				}
				dotCount++
				if dotCount > 2 {
					break
				}
				digitRun = 0
				j++
				continue
			}
			break
		}
		if dotCount == 2 && digitRun > 0 {
			return string(rs[start:j]), true
		}
		i = j
	}
	return "", false
}

func highlightEvoVersionPhrases(s string) string {
	s = compatibleVersionRe.ReplaceAllStringFunc(s, func(m string) string {
		sub := compatibleVersionRe.FindStringSubmatch(m)
		if len(sub) < 2 {
			return m
		}
		v := sub[1]
		hi := okStyle.Copy().Bold(true).Render(v)
		return strings.Replace(m, v, hi, 1)
	})

	s = evoDownloadRe.ReplaceAllStringFunc(s, func(m string) string {
		return okStyle.Copy().Bold(true).Render(m)
	})
	return s
}

func splitTrailingPunctuation(s string) (value string, suffix string) {
	s = strings.TrimSpace(s)
	if s == "" {
		return "", ""
	}

	if strings.HasSuffix(s, "...") {
		value = strings.TrimSpace(strings.TrimSuffix(s, "..."))
		suffix = "..."
		return value, suffix
	}
	if strings.HasSuffix(s, "…") {
		value = strings.TrimSpace(strings.TrimSuffix(s, "…"))
		suffix = "…"
		return value, suffix
	}

	last, size := utf8.DecodeLastRuneInString(s)
	if last == utf8.RuneError && size == 0 {
		return s, ""
	}

	switch last {
	case '.', '!', '?':
		value = strings.TrimSpace(s[:len(s)-size])
		suffix = string(last)
		return value, suffix
	default:
		return s, ""
	}
}

func (m *Model) renderLogPanelBody(width int, height int) string {
	if width <= 0 || height <= 0 {
		return ""
	}

	lines := make([]string, 0, height)
	lines = append(lines, splitLines(m.logVP.View())...)

	if m.layout.logQuestionH > 0 {
		lines = append(lines, m.renderQuestionLines(width, m.layout.logQuestionH)...)
	}

	if len(lines) > height {
		lines = lines[len(lines)-height:]
	}
	for len(lines) < height {
		lines = append(lines, "")
	}
	return strings.Join(lines, "\n")
}

func (m *Model) renderQuestionLines(width int, height int) []string {
	if width <= 0 || height <= 0 {
		return nil
	}
	if !m.state.Question.Active {
		return fitLines("", width, height)
	}

	out := make([]string, 0, height)

	sep := strings.Repeat("─", width)
	out = append(out, mutedStyle.Render(sep))
	if len(out) >= height {
		return out[:height]
	}

	prompt := truncatePlain("? "+m.state.Question.Prompt, width)
	out = append(out, questionStyle.Render(prompt))
	if len(out) >= height {
		return out[:height]
	}

	if m.state.Question.Kind == domain.QuestionInput {
		// Render the active text input line.
		v := ""
		if m.inputTouched {
			v = m.inputValue
			if m.state.Question.Secret && v != "" {
				v = strings.Repeat("•", len([]rune(v)))
			}
			v = inputStyle.Render(v)
		} else if m.state.Question.Default != "" {
			def := m.state.Question.Default
			if m.state.Question.Secret && def != "" {
				def = strings.Repeat("•", len([]rune(def)))
			}
			v = defaultInputStyle.Render(def)
		}
		inputLine := "> " + v
		out = append(out, truncateANSI(inputLine, width))
		for len(out) < height {
			out = append(out, "")
		}
		return out[:height]
	}

	remaining := height - len(out)
	if remaining <= 0 {
		return out
	}

	opts := m.state.Question.Options
	if len(opts) == 0 {
		for len(out) < height {
			out = append(out, "")
		}
		return out[:height]
	}

	visible := remaining
	if visible > len(opts) {
		visible = len(opts)
	}

	// Sliding window: keep the selected option within the visible range.
	start := 0
	if len(opts) > visible {
		start = m.state.Question.Selected - visible/2
		if start < 0 {
			start = 0
		}
		maxStart := len(opts) - visible
		if start > maxStart {
			start = maxStart
		}
	}

	for i := 0; i < visible; i++ {
		idx := start + i
		opt := opts[idx]
		icon := "○"
		style := mutedStyle
		if idx == m.state.Question.Selected {
			icon = "●"
			style = inputStyle.Copy().Bold(true)
		}
		label := opt.Label
		if !opt.Enabled && opt.Reason != "" {
			label += " — " + opt.Reason
			style = mutedStyle
		}
		line := truncatePlain(fmt.Sprintf("  %s %s", icon, label), width)
		out = append(out, style.Render(line))
		if len(out) >= height {
			return out[:height]
		}
	}

	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func panel(title string, body string, width int, height int) string {
	if width <= 0 || height <= 0 {
		return ""
	}

	border := panelBorder
	innerW := max(0, width-2)
	innerH := max(0, height-2)
	contentW := panelContentWidth(width)

	var lines []string
	lines = append(lines, fitLines(body, contentW, innerH)...)
	for len(lines) < innerH {
		lines = append(lines, strings.Repeat(" ", contentW))
	}
	if len(lines) > innerH {
		lines = lines[:innerH]
	}

	var out []string
	out = append(out, topBorderWithTitle(width, title, border))
	for _, l := range lines {
		out = append(out, border.Left+" "+padRight(truncateANSI(l, contentW), contentW)+" "+border.Right)
	}
	out = append(out, border.BottomLeft+strings.Repeat(border.Bottom, innerW)+border.BottomRight)
	return strings.Join(out, "\n")
}

func stepMarker(status domain.StepStatus) (string, lipgloss.Style, lipgloss.Style) {
	switch status {
	case domain.StepDone:
		return "✔", okStyle, lipgloss.NewStyle()
	case domain.StepActive:
		return "▣", activeStyle, lipgloss.NewStyle().Bold(true)
	case domain.StepWarn:
		// "Done with warnings" still reads better as a completed checkbox.
		return "✔", warnStyle, lipgloss.NewStyle()
	case domain.StepError:
		return "✖", errStyle, lipgloss.NewStyle()
	default:
		return "□", mutedStyle, mutedStyle
	}
}

func statusIndicator(level domain.StatusLevel) (string, lipgloss.Style) {
	switch level {
	case domain.StatusOK:
		return "●", okStyle
	case domain.StatusWarn:
		return "●", warnStyle
	default:
		return "●", errStyle
	}
}

func logPrefix(level domain.LogLevel) (string, lipgloss.Style) {
	switch level {
	case domain.LogError:
		return "✖", errStyle
	case domain.LogWarning:
		return "⚠", warnStyle
	default:
		return "•", mutedStyle
	}
}

func fitLines(s string, width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	raw := splitLines(s)
	out := make([]string, 0, height)
	for i := 0; i < height; i++ {
		line := ""
		if i < len(raw) {
			line = raw[i]
		}
		out = append(out, padRight(truncateANSI(line, width), width))
	}
	return out
}

func splitLines(s string) []string {
	if s == "" {
		return nil
	}
	return strings.Split(strings.TrimRight(s, "\n"), "\n")
}

func truncatePlain(s string, width int) string {
	if width <= 0 {
		return ""
	}
	if runewidth.StringWidth(s) <= width {
		return s
	}
	if width <= 1 {
		return runewidth.Truncate(s, width, "")
	}
	return runewidth.Truncate(s, width, "…")
}

func truncateANSI(s string, width int) string {
	if width <= 0 {
		return ""
	}
	if lipgloss.Width(s) <= width {
		return s
	}
	if width <= 1 {
		return reflowtruncate.String(s, uint(width))
	}
	return reflowtruncate.StringWithTail(s, uint(width), "…")
}

func minSizeView(width int, height int) string {
	if width <= 0 || height <= 0 {
		return "Increase terminal size"
	}
	msgText := "Increase terminal size"
	if width < 22 {
		msgText = "Increase size"
	}
	msg := lipgloss.NewStyle().Bold(true).Render(truncatePlain(msgText, width))
	sub := lipgloss.NewStyle().Faint(true).Render(truncatePlain(fmt.Sprintf("Current: %dx%d", width, height), width))
	return lipgloss.Place(width, height, lipgloss.Center, lipgloss.Center, msg+"\n"+sub)
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
