package ui

import (
	"context"

	tea "github.com/charmbracelet/bubbletea"

	"github.com/evolution-cms/installer/internal/domain"
)

func Run(ctx context.Context, mode Mode, events <-chan domain.Event, meta Meta) error {
	return RunWithCancel(ctx, mode, events, nil, meta, nil)
}

func RunWithCancel(ctx context.Context, mode Mode, events <-chan domain.Event, actions chan<- domain.Action, meta Meta, cancel func()) error {
	m := NewModel(ctx, mode, events, actions, meta, cancel)
	p := tea.NewProgram(m, tea.WithAltScreen())
	_, err := p.Run()
	return err
}
