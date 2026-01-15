package ui

import "github.com/charmbracelet/lipgloss"

const progressFillHex = "#00D787"
const brightBlue = "#3b82f6"

var (
	panelBorder     = lipgloss.RoundedBorder()
	panelStyle      = lipgloss.NewStyle().Border(panelBorder)
	panelTitleStyle = lipgloss.NewStyle().Bold(true)

	activeStyle = lipgloss.NewStyle().Foreground(lipgloss.Color(brightBlue))
	okStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color("10"))
	warnStyle  = lipgloss.NewStyle().Foreground(lipgloss.Color("11"))
	errStyle   = lipgloss.NewStyle().Foreground(lipgloss.Color("9"))
	mutedStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("8"))

	logoStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color(progressFillHex))
	versionStyle = lipgloss.NewStyle().Foreground(lipgloss.Color(brightBlue))
	taglineStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("11"))

	questionStyle     = versionStyle
	defaultInputStyle = mutedStyle
	inputStyle        = lipgloss.NewStyle().Foreground(lipgloss.Color("7"))
)
