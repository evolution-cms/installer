package ui

import "github.com/charmbracelet/lipgloss"

const progressFillHex = "#00D787"

var (
	panelBorder     = lipgloss.RoundedBorder()
	panelStyle      = lipgloss.NewStyle().Border(panelBorder)
	panelTitleStyle = lipgloss.NewStyle().Bold(true)

	activeStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("12"))
	okStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color("10"))
	warnStyle  = lipgloss.NewStyle().Foreground(lipgloss.Color("11"))
	errStyle   = lipgloss.NewStyle().Foreground(lipgloss.Color("9"))
	mutedStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("8"))

	logoStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color(progressFillHex))
	versionStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("12"))
	taglineStyle = lipgloss.NewStyle().Foreground(lipgloss.Color("11"))

	questionStyle     = versionStyle
	defaultInputStyle = mutedStyle
	inputStyle        = lipgloss.NewStyle().Foreground(lipgloss.Color("7"))
)
