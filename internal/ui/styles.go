package ui

import "github.com/charmbracelet/lipgloss"

const progressFillHex = "#00D787"
const brightBlue = "#60a5fa"
const brightRed = "#ef4444"
const brightYellow = "#fde047"

var (
	panelBorder     = lipgloss.RoundedBorder()
	panelStyle      = lipgloss.NewStyle().Border(panelBorder)
	panelTitleStyle = lipgloss.NewStyle().Bold(true)

	activeStyle = lipgloss.NewStyle().Foreground(lipgloss.Color(brightBlue))
	okStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color("10"))
	warnStyle  = lipgloss.NewStyle().Foreground(lipgloss.Color(brightYellow))
	errStyle   = lipgloss.NewStyle().Foreground(lipgloss.Color(brightRed))
	mutedStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("8"))

	logoStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color(progressFillHex))
	versionStyle = lipgloss.NewStyle().Foreground(lipgloss.Color(brightBlue))
	taglineStyle = lipgloss.NewStyle().Foreground(lipgloss.Color(brightYellow))

	questionStyle     = versionStyle
	defaultInputStyle = mutedStyle
	inputStyle        = lipgloss.NewStyle().Foreground(lipgloss.Color("7"))
)
