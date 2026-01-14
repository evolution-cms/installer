package ui

import (
	"context"
	"os"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/x/term"

	"github.com/evolution-cms/installer/internal/domain"
)

func Run(ctx context.Context, mode Mode, events <-chan domain.Event, meta Meta) error {
	return RunWithCancel(ctx, mode, events, nil, meta, nil)
}

func RunWithCancel(ctx context.Context, mode Mode, events <-chan domain.Event, actions chan<- domain.Action, meta Meta, cancel func()) error {
	m := NewModel(ctx, mode, events, actions, meta, cancel)

	// Bubble Tea relies on terminal size messages to render the UI. In some environments
	// (e.g., wrapped CLIs, certain PTYs, or misconfigured terminals) the size cannot be
	// detected, leaving the UI stuck at the "Loading…" placeholder. Seed a sensible
	// initial size so the UI can render and progress even if WindowSizeMsg never arrives.
	if w, h, err := term.GetSize(os.Stdout.Fd()); err == nil && w > 0 && h > 0 {
		m.width = w
		m.height = h
		m.reflow()
	} else {
		m.width = 80
		m.height = 24
		m.reflow()
	}

	p := tea.NewProgram(m, tea.WithAltScreen())
	_, err := p.Run()
	return err
}
