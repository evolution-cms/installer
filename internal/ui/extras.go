package ui

import (
	"fmt"
	"strings"

	"github.com/charmbracelet/lipgloss"

	"github.com/evolution-cms/installer/internal/domain"
)

type extrasFocus int

const (
	extrasFocusList extrasFocus = iota
	extrasFocusActions
)

const (
	uiExtrasSelectID = "extras_select"
	uiExtrasInstall  = "install"
	uiExtrasSkip     = "skip"
)

type extrasUIState struct {
	active bool
	stage  domain.ExtrasStage

	packages []domain.ExtrasPackage
	selected map[string]bool
	versions map[string]string
	cursor   int
	focus    extrasFocus
	action   int

	versionPickerActive  bool
	versionPickerPkg     string
	versionPickerCursor  int
	versionPickerOptions []string
	versionPickerValues  []string

	results       []domain.ExtrasItemResult
	current       string
	currentIndex  int
	total         int
	details       []domain.ExtrasItemDetail
	showDetails   bool
	summaryCursor int
}

func (m *Model) applyExtrasState(state domain.ExtrasState) {
	if !state.Active {
		m.extras = extrasUIState{}
		return
	}

	packageChanged := !extrasPackagesEqual(m.extras.packages, state.Packages)

	m.extras.active = true
	m.extras.stage = state.Stage
	m.extras.results = state.Results
	m.extras.current = state.Current
	m.extras.currentIndex = state.CurrentIndex
	m.extras.total = state.Total
	m.extras.details = state.Details
	if state.Stage != domain.ExtrasStageSelect {
		m.extras.versionPickerActive = false
	}

	if state.Stage == domain.ExtrasStageSelect {
		m.extras.packages = state.Packages
		if m.extras.selected == nil || m.extras.versions == nil || packageChanged {
			m.extras.selected = map[string]bool{}
			m.extras.versions = map[string]string{}
			for _, sel := range state.Selections {
				name := strings.TrimSpace(sel.Name)
				if name == "" {
					continue
				}
				m.extras.selected[name] = true
				if v := strings.TrimSpace(sel.Version); v != "" {
					m.extras.versions[name] = v
				}
			}
			m.extras.cursor = 0
			m.extras.focus = extrasFocusList
			m.extras.action = 0
			m.extras.versionPickerActive = false
		}
		if len(m.extras.packages) == 0 {
			m.extras.cursor = 0
			m.extras.focus = extrasFocusActions
		} else if m.extras.cursor >= len(m.extras.packages) {
			m.extras.cursor = len(m.extras.packages) - 1
		}
		return
	}

	if state.Stage == domain.ExtrasStageSummary {
		m.extras.action = 0
		m.extras.focus = extrasFocusActions
		m.extras.showDetails = true
		m.extras.versionPickerActive = false
		if len(state.Results) > 0 {
			if m.extras.summaryCursor < 0 {
				m.extras.summaryCursor = 0
			}
			if m.extras.summaryCursor >= len(state.Results) {
				m.extras.summaryCursor = len(state.Results) - 1
			}
		} else {
			m.extras.summaryCursor = 0
		}
	}
}

func extrasPackagesEqual(a []domain.ExtrasPackage, b []domain.ExtrasPackage) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i].Name != b[i].Name || a[i].Version != b[i].Version {
			return false
		}
		if len(a[i].Versions) != len(b[i].Versions) {
			return false
		}
		for j := range a[i].Versions {
			if a[i].Versions[j] != b[i].Versions[j] {
				return false
			}
		}
	}
	return true
}

func (m *Model) handleExtrasKey(key string, lowerKey string) bool {
	if !m.extras.active {
		return false
	}
	switch m.extras.stage {
	case domain.ExtrasStageSelect:
		m.handleExtrasSelectKey(key, lowerKey)
		return true
	case domain.ExtrasStageSummary:
		m.handleExtrasSummaryKey(lowerKey)
		return true
	case domain.ExtrasStageProgress:
		return isExtrasNavKey(lowerKey)
	default:
		return true
	}
}

func isExtrasNavKey(key string) bool {
	switch key {
	case "up", "down", "left", "right", "pgup", "pageup", "pgdown", "pagedown", "home", "end", "tab", "shift+tab", "enter", " ":
		return true
	default:
		return false
	}
}

func (m *Model) handleExtrasSelectKey(key string, lowerKey string) {
	if m.extras.versionPickerActive {
		m.handleExtrasVersionPickerKey(lowerKey)
		return
	}
	listLen := len(m.extras.packages)
	listHeight := m.extrasListHeight()

	toggleFocus := func() {
		if m.extras.focus == extrasFocusList {
			m.extras.focus = extrasFocusActions
		} else {
			m.extras.focus = extrasFocusList
		}
	}

	switch lowerKey {
	case "tab":
		toggleFocus()
		return
	case "shift+tab":
		toggleFocus()
		return
	}

	if m.extras.focus == extrasFocusActions {
		switch lowerKey {
		case "up":
			if listLen > 0 {
				m.extras.focus = extrasFocusList
				if m.extras.cursor >= listLen {
					m.extras.cursor = listLen - 1
				}
			}
			return
		case "left", "right", "tab", "shift+tab":
			if m.extras.action == 0 {
				m.extras.action = 1
			} else {
				m.extras.action = 0
			}
		case "enter":
			selected := m.extrasSelectedNames()
			if m.extras.action == 0 {
				if len(selected) == 0 {
					return
				}
				m.sendAction(domain.Action{
					Type:       domain.ActionExtrasDecision,
					QuestionID: uiExtrasSelectID,
					OptionID:   uiExtrasInstall,
					Values:     selectionsToValues(selected),
					Extras:     selected,
				})
				return
			}
			m.sendAction(domain.Action{
				Type:       domain.ActionExtrasDecision,
				QuestionID: uiExtrasSelectID,
				OptionID:   uiExtrasSkip,
			})
		}
		return
	}

	switch lowerKey {
	case "up":
		m.extras.cursor--
	case "down":
		if m.extras.cursor >= listLen-1 {
			m.extras.focus = extrasFocusActions
			m.extras.action = 0
			return
		}
		m.extras.cursor++
	case "pgup", "pageup":
		m.extras.cursor -= listHeight
	case "pgdown", "pagedown":
		m.extras.cursor += listHeight
	case "home":
		m.extras.cursor = 0
	case "end":
		m.extras.cursor = listLen - 1
	case "enter", "v":
		if listLen == 0 || m.extras.cursor < 0 || m.extras.cursor >= listLen {
			return
		}
		pkg := m.extras.packages[m.extras.cursor]
		m.openExtrasVersionPicker(pkg)
		return
	case " ":
		if listLen == 0 || m.extras.cursor < 0 || m.extras.cursor >= listLen {
			return
		}
		name := m.extras.packages[m.extras.cursor].Name
		if name == "" {
			return
		}
		if m.extras.selected == nil {
			m.extras.selected = map[string]bool{}
		}
		if m.extras.selected[name] {
			delete(m.extras.selected, name)
			if m.extras.versions != nil {
				delete(m.extras.versions, name)
			}
		} else {
			m.extras.selected[name] = true
		}
	}

	if listLen <= 0 {
		m.extras.cursor = 0
		return
	}
	if m.extras.cursor < 0 {
		m.extras.cursor = 0
	}
	if m.extras.cursor >= listLen {
		m.extras.cursor = listLen - 1
	}
}

func (m *Model) handleExtrasSummaryKey(lowerKey string) {
	switch lowerKey {
	case "up":
		m.extras.summaryCursor--
	case "down":
		m.extras.summaryCursor++
	case "pgup", "pageup":
		m.extras.summaryCursor -= 5
	case "pgdown", "pagedown":
		m.extras.summaryCursor += 5
	case "home":
		m.extras.summaryCursor = 0
	case "end":
		m.extras.summaryCursor = len(m.extras.results) - 1
	case "enter", "esc", "q":
		m.quitRequested = true
	}

	if len(m.extras.results) == 0 {
		m.extras.summaryCursor = 0
		return
	}
	if m.extras.summaryCursor < 0 {
		m.extras.summaryCursor = 0
	}
	if m.extras.summaryCursor >= len(m.extras.results) {
		m.extras.summaryCursor = len(m.extras.results) - 1
	}
}

func (m *Model) extrasSelectedNames() []domain.ExtrasSelection {
	if len(m.extras.packages) == 0 || len(m.extras.selected) == 0 {
		return nil
	}
	out := make([]domain.ExtrasSelection, 0, len(m.extras.selected))
	for _, pkg := range m.extras.packages {
		if pkg.Name == "" {
			continue
		}
		if m.extras.selected[pkg.Name] {
			version := ""
			if m.extras.versions != nil {
				version = strings.TrimSpace(m.extras.versions[pkg.Name])
			}
			out = append(out, domain.ExtrasSelection{Name: pkg.Name, Version: version})
		}
	}
	return out
}

func selectionsToValues(selections []domain.ExtrasSelection) []string {
	if len(selections) == 0 {
		return nil
	}
	out := make([]string, 0, len(selections))
	for _, sel := range selections {
		name := strings.TrimSpace(sel.Name)
		if name == "" {
			continue
		}
		version := strings.TrimSpace(sel.Version)
		if version == "" {
			out = append(out, name)
		} else {
			out = append(out, name+"@"+version)
		}
	}
	return out
}

func (m *Model) openExtrasVersionPicker(pkg domain.ExtrasPackage) {
	name := strings.TrimSpace(pkg.Name)
	if name == "" {
		return
	}
	options, values := buildExtrasVersionOptions(pkg)
	if len(options) == 0 {
		return
	}
	m.extras.versionPickerActive = true
	m.extras.versionPickerPkg = name
	m.extras.versionPickerOptions = options
	m.extras.versionPickerValues = values
	m.extras.versionPickerCursor = 0

	current := ""
	if m.extras.versions != nil {
		current = strings.TrimSpace(m.extras.versions[name])
	}
	for i, v := range values {
		if strings.TrimSpace(v) == current {
			m.extras.versionPickerCursor = i
			break
		}
	}
}

func (m *Model) closeExtrasVersionPicker() {
	m.extras.versionPickerActive = false
	m.extras.versionPickerPkg = ""
	m.extras.versionPickerCursor = 0
	m.extras.versionPickerOptions = nil
	m.extras.versionPickerValues = nil
}

func (m *Model) handleExtrasVersionPickerKey(lowerKey string) {
	total := len(m.extras.versionPickerOptions)
	if total == 0 {
		m.closeExtrasVersionPicker()
		return
	}
	switch lowerKey {
	case "esc":
		m.closeExtrasVersionPicker()
		return
	case "up":
		m.extras.versionPickerCursor--
	case "down":
		m.extras.versionPickerCursor++
	case "pgup", "pageup":
		m.extras.versionPickerCursor -= 5
	case "pgdown", "pagedown":
		m.extras.versionPickerCursor += 5
	case "home":
		m.extras.versionPickerCursor = 0
	case "end":
		m.extras.versionPickerCursor = total - 1
	case "enter", " ":
		idx := m.extras.versionPickerCursor
		if idx < 0 || idx >= total {
			return
		}
		name := strings.TrimSpace(m.extras.versionPickerPkg)
		if name != "" {
			if m.extras.selected == nil {
				m.extras.selected = map[string]bool{}
			}
			if m.extras.versions == nil {
				m.extras.versions = map[string]string{}
			}
			m.extras.selected[name] = true
			val := strings.TrimSpace(m.extras.versionPickerValues[idx])
			if val == "" {
				delete(m.extras.versions, name)
			} else {
				m.extras.versions[name] = val
			}
		}
		m.closeExtrasVersionPicker()
		return
	}
	if m.extras.versionPickerCursor < 0 {
		m.extras.versionPickerCursor = 0
	}
	if m.extras.versionPickerCursor >= total {
		m.extras.versionPickerCursor = total - 1
	}
}

func buildExtrasVersionOptions(pkg domain.ExtrasPackage) ([]string, []string) {
	labels := []string{}
	values := []string{}

	defaultLabel := "Default (auto)"
	if v := strings.TrimSpace(pkg.Version); v != "" {
		defaultLabel += " - " + v
	} else if b := strings.TrimSpace(pkg.DefaultBranch); b != "" {
		defaultLabel += " - " + b
	}
	labels = append(labels, defaultLabel)
	values = append(values, "")

	seen := map[string]struct{}{}
	add := func(v string) {
		v = strings.TrimSpace(v)
		if v == "" {
			return
		}
		if _, ok := seen[v]; ok {
			return
		}
		seen[v] = struct{}{}
		labels = append(labels, v)
		values = append(values, v)
	}

	add(pkg.Version)
	for _, v := range pkg.Versions {
		add(v)
	}
	add(pkg.DefaultBranch)

	return labels, values
}

func (m *Model) extrasListHeight() int {
	usableH := max(0, m.height-1)
	innerH := max(0, usableH-2)
	headerLines := 2
	footerLines := 2
	h := innerH - headerLines - footerLines
	if h < 1 {
		h = 1
	}
	return h
}

func (m *Model) renderExtrasView(width int, height int) string {
	if width <= 0 || height <= 0 {
		return ""
	}
	switch m.extras.stage {
	case domain.ExtrasStageSelect:
		return m.renderExtrasSelect(width, height)
	case domain.ExtrasStageProgress:
		return m.renderExtrasProgress(width, height)
	case domain.ExtrasStageSummary:
		return m.renderExtrasSummary(width, height)
	default:
		return panel("Extras", "", width, height)
	}
}

func (m *Model) renderExtrasSelect(width int, height int) string {
	innerH := max(0, height-2)
	if innerH < 6 {
		return minSizeView(width, height)
	}
	contentW := panelContentWidth(width)

	lines := []string{
		truncatePlain("Select extras to install.", contentW),
		"",
	}

	listHeight := innerH - len(lines) - 2
	if listHeight < 1 {
		listHeight = 1
	}
	lines = append(lines, m.renderExtrasList(contentW, listHeight)...)
	lines = append(lines, "", m.renderExtrasSelectActions(contentW))

	body := strings.Join(lines, "\n")
	base := panel("Extras selection", body, width, height)
	if m.extras.versionPickerActive {
		modal := m.renderExtrasVersionPicker(width, height)
		if modal != "" {
			x := max(0, (width-lipgloss.Width(modal))/2)
			y := max(0, (height-len(splitLines(modal)))/2)
			return overlayAt(base, width, height, modal, x, y)
		}
	}
	return base
}

func (m *Model) renderExtrasVersionPicker(width int, height int) string {
	if width <= 0 || height <= 0 {
		return ""
	}
	options := m.extras.versionPickerOptions
	if len(options) == 0 {
		return ""
	}

	boxW := width - 6
	if boxW > 72 {
		boxW = 72
	}
	if boxW < 24 {
		boxW = max(18, width-2)
	}

	maxBodyH := height - 6
	if maxBodyH < 4 {
		maxBodyH = 4
	}
	listH := len(options)
	if listH > maxBodyH-3 {
		listH = max(1, maxBodyH-3)
	}
	boxH := listH + 4
	if boxH > height-2 {
		boxH = height - 2
	}
	if boxH < 7 {
		boxH = 7
	}

	contentW := panelContentWidth(boxW)
	lines := []string{
		truncatePlain("Select version for "+m.extras.versionPickerPkg, contentW),
		"",
	}
	lines = append(lines, m.renderExtrasVersionOptions(contentW, listH)...)
	lines = append(lines, "", truncatePlain("Enter: select  Esc: cancel", contentW))

	body := strings.Join(lines, "\n")
	return panel("Package version", body, boxW, boxH)
}

func (m *Model) renderExtrasProgress(width int, height int) string {
	innerH := max(0, height-2)
	if innerH < 6 {
		return minSizeView(width, height)
	}
	contentW := panelContentWidth(width)

	title := "Installing extras"
	if m.extras.total > 0 {
		title = fmt.Sprintf("Installing extras (%d/%d)", m.extras.currentIndex, m.extras.total)
	}
	current := "Current: " + m.extras.current
	if strings.TrimSpace(m.extras.current) == "" {
		current = "Preparing extras..."
	}
	lines := []string{
		truncatePlain(title, contentW),
		truncatePlain(current, contentW),
		"",
	}

	remaining := innerH - len(lines)
	if remaining < 1 {
		remaining = 1
	}

	if len(m.extras.details) > 0 && remaining > 6 {
		outputH := remaining / 3
		if outputH < 3 {
			outputH = 3
		}
		if outputH > 6 {
			outputH = 6
		}
		resultsH := remaining - (outputH + 2)
		if resultsH < 1 {
			resultsH = 1
			outputH = max(1, remaining-resultsH-2)
		}
		lines = append(lines, m.renderExtrasResults(contentW, resultsH, true)...)
		lines = append(lines, "", truncatePlain("Recent output:", contentW))
		lines = append(lines, m.renderExtrasRecentOutput(contentW, outputH)...)
	} else {
		listHeight := remaining
		if listHeight < 1 {
			listHeight = 1
		}
		lines = append(lines, m.renderExtrasResults(contentW, listHeight, true)...)
	}

	body := strings.Join(lines, "\n")
	return panel("Extras progress", body, width, height)
}

func (m *Model) renderExtrasSummary(width int, height int) string {
	innerH := max(0, height-2)
	if innerH < 6 {
		return minSizeView(width, height)
	}
	contentW := panelContentWidth(width)

	lines := []string{
		truncatePlain("Extras installation summary.", contentW),
		"",
	}

	contentHeight := innerH - len(lines) - 2
	if contentHeight < 1 {
		contentHeight = 1
	}

	if len(m.extras.details) > 0 {
		detailsLabelH := 1
		contentMain := contentHeight - detailsLabelH
		if contentMain < 1 {
			contentMain = 1
		}
		resH := max(1, contentMain/2)
		detH := max(1, contentMain-resH)
		lines = append(lines, m.renderExtrasSummaryResults(contentW, resH)...)
		lines = append(lines, "", truncatePlain("Details:", contentW))
		lines = append(lines, m.renderExtrasSummaryDetail(contentW, detH)...)
	} else {
		lines = append(lines, m.renderExtrasSummaryResults(contentW, contentHeight)...)
	}

	lines = append(lines, "", m.renderExtrasSummaryActions(contentW))
	body := strings.Join(lines, "\n")
	return panel("Extras summary", body, width, height)
}

func (m *Model) renderExtrasSummaryResults(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.results) == 0 {
		return fitLines("(no results yet)", width, height)
	}

	visible := height
	if visible > len(m.extras.results) {
		visible = len(m.extras.results)
	}
	start := 0
	if len(m.extras.results) > visible {
		start = m.extras.summaryCursor - visible/2
		if start < 0 {
			start = 0
		}
		maxStart := len(m.extras.results) - visible
		if start > maxStart {
			start = maxStart
		}
	}

	out := make([]string, 0, height)
	for i := 0; i < visible; i++ {
		idx := start + i
		r := m.extras.results[idx]
		icon, style := extrasStatusMarker(r.Status)
		cursor := " "
		if idx == m.extras.summaryCursor {
			cursor = ">"
			style = style.Copy().Bold(true)
		}
		label := r.Name
		if r.Status == domain.ExtrasStatusError && r.Message != "" {
			label += " - " + r.Message
		}
		line := truncatePlain(fmt.Sprintf("%s %s %s", cursor, icon, label), width)
		out = append(out, style.Render(line))
	}
	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func (m *Model) renderExtrasSummaryDetail(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.results) == 0 {
		return fitLines("(no details)", width, height)
	}

	idx := m.extras.summaryCursor
	if idx < 0 {
		idx = 0
	}
	if idx >= len(m.extras.results) {
		idx = len(m.extras.results) - 1
	}
	name := strings.TrimSpace(m.extras.results[idx].Name)

	var detail *domain.ExtrasItemDetail
	for i := len(m.extras.details) - 1; i >= 0; i-- {
		if strings.TrimSpace(m.extras.details[i].Name) == name {
			detail = &m.extras.details[i]
			break
		}
	}

	lines := []string{}
	if name != "" {
		lines = append(lines, truncatePlain(">> "+name, width))
	}
	if detail != nil {
		for _, line := range splitLines(detail.Output) {
			if strings.TrimSpace(line) == "" {
				continue
			}
			lines = append(lines, truncatePlain("  "+line, width))
		}
	}
	if len(lines) == 0 {
		lines = append(lines, "(no output captured)")
	}
	if len(lines) > height {
		lines = lines[len(lines)-height:]
	}
	return fitLines(strings.Join(lines, "\n"), width, height)
}

func (m *Model) renderExtrasList(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.packages) == 0 {
		return fitLines("(no extras found)", width, height)
	}

	visible := height
	if visible > len(m.extras.packages) {
		visible = len(m.extras.packages)
	}
	start := 0
	if len(m.extras.packages) > visible {
		start = m.extras.cursor - visible/2
		if start < 0 {
			start = 0
		}
		maxStart := len(m.extras.packages) - visible
		if start > maxStart {
			start = maxStart
		}
	}

	out := make([]string, 0, height)
	for i := 0; i < visible; i++ {
		idx := start + i
		pkg := m.extras.packages[idx]

		cursor := " "
		style := mutedStyle
		if idx == m.extras.cursor && m.extras.focus == extrasFocusList {
			cursor = ">"
			style = inputStyle.Copy().Bold(true)
		}

		checked := "[ ]"
		if m.extras.selected != nil && m.extras.selected[pkg.Name] {
			checked = "[x]"
		}

		version := ""
		if m.extras.versions != nil {
			version = strings.TrimSpace(m.extras.versions[pkg.Name])
		}
		if version == "" {
			version = strings.TrimSpace(pkg.Version)
		}
		if version == "" {
			version = strings.TrimSpace(pkg.DefaultBranch)
		}
		if version == "" {
			version = "default"
		}
		desc := strings.TrimSpace(pkg.Description)
		label := fmt.Sprintf("%s %s %s @ %s", cursor, checked, pkg.Name, version)
		if desc != "" {
			label += " - " + desc
		}

		line := truncatePlain(label, width)
		out = append(out, style.Render(line))
	}

	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func (m *Model) renderExtrasVersionOptions(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	options := m.extras.versionPickerOptions
	if len(options) == 0 {
		return fitLines("(no versions found)", width, height)
	}

	visible := height
	if visible > len(options) {
		visible = len(options)
	}
	start := 0
	if len(options) > visible {
		start = m.extras.versionPickerCursor - visible/2
		if start < 0 {
			start = 0
		}
		maxStart := len(options) - visible
		if start > maxStart {
			start = maxStart
		}
	}

	out := make([]string, 0, height)
	for i := 0; i < visible; i++ {
		idx := start + i
		opt := options[idx]
		cursor := " "
		style := mutedStyle
		if idx == m.extras.versionPickerCursor {
			cursor = ">"
			style = inputStyle.Copy().Bold(true)
		}
		line := truncatePlain(fmt.Sprintf(" %s %s", cursor, opt), width)
		out = append(out, style.Render(line))
	}
	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func (m *Model) renderExtrasResults(width int, height int, showMessages bool) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.results) == 0 {
		return fitLines("(no results yet)", width, height)
	}

	out := make([]string, 0, height)
	visible := height
	if visible > len(m.extras.results) {
		visible = len(m.extras.results)
	}

	for i := 0; i < visible; i++ {
		r := m.extras.results[i]
		icon, style := extrasStatusMarker(r.Status)
		label := r.Name
		if showMessages && r.Status == domain.ExtrasStatusError && r.Message != "" {
			label += " - " + r.Message
		}
		if r.Status == domain.ExtrasStatusRunning {
			if !m.pulseOn {
				style = mutedStyle
			}
			avail := max(0, width-2)
			label = truncatePlain(label, avail)
			line := style.Render(icon) + " " + label
			out = append(out, truncateANSI(line, width))
			continue
		}
		line := truncatePlain(fmt.Sprintf("%s %s", icon, label), width)
		out = append(out, style.Render(line))
	}
	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func (m *Model) renderExtrasRecentOutput(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.details) == 0 {
		return fitLines("(no output yet)", width, height)
	}
	detail := m.extras.details[len(m.extras.details)-1]
	lines := []string{}
	if strings.TrimSpace(detail.Name) != "" {
		lines = append(lines, truncatePlain(">> "+detail.Name, width))
	}
	for _, line := range splitLines(detail.Output) {
		if strings.TrimSpace(line) == "" {
			continue
		}
		lines = append(lines, truncatePlain(line, width))
	}
	return fitLines(strings.Join(lines, "\n"), width, height)
}

func (m *Model) renderExtrasDetails(width int, height int) []string {
	if height <= 0 || width <= 0 {
		return nil
	}
	if len(m.extras.details) == 0 {
		return fitLines("(no details)", width, height)
	}

	details := m.extras.details
	itemCount := len(details)
	if itemCount == 0 {
		return fitLines("(no details)", width, height)
	}

	if height <= itemCount {
		out := make([]string, 0, height)
		for i := 0; i < itemCount && len(out) < height; i++ {
			title := strings.TrimSpace(details[i].Name)
			if title == "" {
				title = "(extra)"
			}
			out = append(out, truncatePlain(">> "+title, width))
		}
		for len(out) < height {
			out = append(out, "")
		}
		return out[:height]
	}

	remaining := height - itemCount
	perItem := remaining / itemCount
	extra := remaining % itemCount

	out := make([]string, 0, height)
	for i, detail := range details {
		if len(out) >= height {
			break
		}
		title := strings.TrimSpace(detail.Name)
		if title == "" {
			title = "(extra)"
		}
		out = append(out, truncatePlain(">> "+title, width))
		maxLines := perItem
		if i < extra {
			maxLines++
		}
		if maxLines <= 0 {
			continue
		}
		lines := []string{}
		for _, line := range splitLines(detail.Output) {
			if strings.TrimSpace(line) == "" {
				continue
			}
			lines = append(lines, line)
		}
		if len(lines) == 0 {
			lines = []string{"(no output captured)"}
		}
		if len(lines) > maxLines {
			lines = lines[len(lines)-maxLines:]
		}
		for _, line := range lines {
			if len(out) >= height {
				break
			}
			out = append(out, truncatePlain("  "+line, width))
		}
	}
	for len(out) < height {
		out = append(out, "")
	}
	return out[:height]
}

func extrasStatusMarker(status domain.ExtrasItemStatus) (string, lipgloss.Style) {
	switch status {
	case domain.ExtrasStatusSuccess:
		return "v", okStyle
	case domain.ExtrasStatusError:
		return "x", errStyle
	case domain.ExtrasStatusRunning:
		return "•", activeStyle
	default:
		return ".", mutedStyle
	}
}

func (m *Model) renderExtrasSelectActions(width int) string {
	selected := m.extrasSelectedNames()
	installLabel := " Install selected "
	skipLabel := " Back/Skip "

	installStyle := mutedStyle
	skipStyle := mutedStyle

	if m.extras.focus == extrasFocusActions {
		if m.extras.action == 0 {
			installStyle = okStyle.Copy().Bold(true)
			skipStyle = mutedStyle
		} else {
			skipStyle = warnStyle.Copy().Bold(true)
			installStyle = mutedStyle
		}
	}

	if len(selected) == 0 {
		installStyle = mutedStyle
	}

	install := installStyle.Render("[" + installLabel + "]")
	skip := skipStyle.Render("[" + skipLabel + "]")
	line := install + "  " + skip
	return padRight(truncateANSI(line, width), width)
}

func (m *Model) renderExtrasSummaryActions(width int) string {
	closeLabel := " Close "
	closeStyle := okStyle.Copy().Bold(true)
	closeBtn := closeStyle.Render("[" + closeLabel + "]")
	return padRight(truncateANSI(closeBtn, width), width)
}
