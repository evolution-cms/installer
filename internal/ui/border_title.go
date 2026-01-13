package ui

import (
	"strings"

	"github.com/charmbracelet/lipgloss"
	"github.com/mattn/go-runewidth"
	reflowtruncate "github.com/muesli/reflow/truncate"
)

func topBorderWithTitle(width int, title string, border lipgloss.Border) string {
	if width <= 0 {
		return ""
	}

	leftCorner := border.TopLeft
	rightCorner := border.TopRight
	h := border.Top

	fillW := width - lipgloss.Width(leftCorner) - lipgloss.Width(rightCorner)
	if fillW < 0 {
		return ""
	}
	if fillW == 0 {
		return leftCorner + rightCorner
	}
	if strings.TrimSpace(title) == "" {
		return leftCorner + repeatToWidth(h, fillW) + rightCorner
	}

	minTitleW := fillW - lipgloss.Width(h) - 2
	if minTitleW < 0 {
		return leftCorner + repeatToWidth(h, fillW) + rightCorner
	}

	rawTitle := cutPlain(strings.TrimSpace(title), minTitleW)
	styledTitle := panelTitleStyle.Render(rawTitle)

	titleBlock := h + " " + styledTitle + " "
	titleW := lipgloss.Width(titleBlock)
	if titleW > fillW {
		titleBlock = cutANSI(titleBlock, fillW)
		titleW = lipgloss.Width(titleBlock)
	}

	restW := max(0, fillW-titleW)
	return leftCorner + titleBlock + repeatToWidth(h, restW) + rightCorner
}

func repeatToWidth(s string, width int) string {
	if width <= 0 || s == "" {
		return ""
	}
	cellW := lipgloss.Width(s)
	if cellW <= 0 {
		return ""
	}

	n := width/cellW + 1
	return cutPlain(strings.Repeat(s, n), width)
}

func cutPlain(s string, width int) string {
	if width <= 0 {
		return ""
	}
	return runewidth.Truncate(s, width, "")
}

func cutANSI(s string, width int) string {
	if width <= 0 {
		return ""
	}
	// Hard-cut without any ellipsis/tail. This is critical for border rendering:
	// the top border line must never display "…" at the end.
	return reflowtruncate.StringWithTail(s, uint(width), "")
}
