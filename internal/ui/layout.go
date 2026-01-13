package ui

import (
	"strings"

	"github.com/charmbracelet/lipgloss"

	"github.com/evolution-cms/installer/internal/domain"
)

type layoutState struct {
	width  int
	height int

	gap  int

	leftW  int
	rightW int

	headerH   int
	questH    int
	logH      int
	logQuestionH int

	topAreaH int

	showLogo bool
	tooSmall bool
}

func (m *Model) reflow() {
	if m.width <= 0 || m.height <= 0 {
		return
	}

	usableH := max(0, m.height-1) // reserve 1 row for the global footer hints
	m.layout = computeLayout(m.width, usableH, len(m.state.Steps))
	if m.layout.tooSmall {
		return
	}

	m.questVP.Width = panelContentWidth(m.layout.leftW)
	m.questVP.Height = panelBodyHeight(m.layout.questH, true)
	m.questVP.SetContent(m.renderSteps(m.questVP.Width))

	m.statusVP.Width = panelContentWidth(m.layout.rightW)
	m.statusVP.Height = panelBodyHeight(m.layout.topAreaH, true)
	m.statusVP.SetContent(m.renderSystem(m.statusVP.Width))

	logContentW := panelContentWidth(m.layout.width)
	logBodyH := panelBodyHeight(m.layout.logH, true)
	m.layout.logQuestionH = m.questionBlockHeight(logContentW, logBodyH)
	logStreamH := max(0, logBodyH-m.layout.logQuestionH)

	m.logVP.Width = logContentW
	m.logVP.Height = logStreamH
	m.logVP.SetContent(m.renderLogStream(logContentW))
	if logStreamH > 0 && m.followLogs {
		m.logVP.GotoBottom()
	}
}

func computeLayout(width int, height int, stepsCount int) layoutState {
	if width >= 90 {
		// Prefer the big ASCII header; fall back if it doesn't fit the current height.
		if l := computeLayoutWithHeader(width, height, stepsCount, true); !l.tooSmall {
			return l
		}
	}
	return computeLayoutWithHeader(width, height, stepsCount, false)
}

func computeLayoutWithHeader(width int, height int, stepsCount int, showLogo bool) layoutState {
	const (
		gap  = 1

		minColW = 40

		minQuestH = 4 // border + title + 1 line
		minLogH   = 5

		sepLines  = 1 // JoinVertical between top/log
	)

	headerH := compactHeaderHeight
	if showLogo {
		headerH = logoHeaderHeight
	}

	l := layoutState{
		width:     width,
		height:    height,
		gap:       gap,
		headerH:   headerH,
		showLogo:  showLogo,
	}

	if width <= 0 || height <= 0 {
		l.tooSmall = true
		return l
	}

	// Equal-width columns with a fixed horizontal gap.
	//
	// outer = 0 (no outer margins)
	available := width - gap
	if available < 2*minColW {
		l.tooSmall = true
		return l
	}
	if height < headerH+sepLines+minQuestH+minLogH {
		l.tooSmall = true
		return l
	}

	left := available / 2
	right := available - left // stable rule: extra column goes to the right
	if left < minColW || right < minColW {
		l.tooSmall = true
		return l
	}
	l.leftW, l.rightW = left, right

	if stepsCount <= 0 {
		stepsCount = 1
	}
	// Title is rendered inline in the top border, so the Quest panel needs:
	//   top border + N lines + bottom border = N + 2
	questWanted := stepsCount + 2
	if questWanted < minQuestH {
		questWanted = minQuestH
	}

	maxTopArea := height - sepLines - minLogH
	if maxTopArea < headerH+minQuestH {
		l.tooSmall = true
		return l
	}

	maxQuest := maxTopArea - headerH
	questH := questWanted
	if questH > maxQuest {
		questH = maxQuest
	}
	if questH < minQuestH {
		l.tooSmall = true
		return l
	}

	l.questH = questH
	l.topAreaH = headerH + questH
	l.logH = height - l.topAreaH - sepLines
	if l.logH < minLogH {
		l.tooSmall = true
		return l
	}

	return l
}

const (
	compactHeaderHeight = 3
	logoHeaderHeight    = 8
)

func panelContentWidth(panelWidth int) int {
	// Border: 2, horizontal padding: 2.
	return max(0, panelWidth-4)
}

func panelBodyHeight(panelHeight int, hasTitle bool) int {
	// Border: 2. Titles are rendered in the top border, so they don't consume height.
	_ = hasTitle
	return max(0, panelHeight-2)
}

func padRight(s string, width int) string {
	if width <= 0 {
		return ""
	}
	w := lipgloss.Width(s)
	if w >= width {
		return s
	}
	return s + strings.Repeat(" ", width-w)
}

func padLeft(s string, width int) string {
	if width <= 0 {
		return ""
	}
	w := lipgloss.Width(s)
	if w >= width {
		return s
	}
	return strings.Repeat(" ", width-w) + s
}

func (m *Model) questionBlockHeight(width int, maxHeight int) int {
	if !m.state.Question.Active || width <= 0 || maxHeight <= 0 {
		return 0
	}

	need := 0
	switch m.state.Question.Kind {
	case domain.QuestionInput:
		need = 1 + 1 + 1 // separator + prompt + input
	default:
		need = 1 + 1 + len(m.state.Question.Options) // separator + prompt + options
	}
	if need < 2 {
		need = 2
	}
	if need > maxHeight {
		return maxHeight
	}
	return need
}
