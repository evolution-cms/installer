package ui

import (
	"context"
	"os"
	"runtime"
	"strconv"

	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/x/term"

	"github.com/evolution-cms/installer/internal/domain"
)

func Run(ctx context.Context, mode Mode, events <-chan domain.Event, meta Meta) error {
	return RunWithCancel(ctx, mode, events, nil, meta, nil)
}

func RunWithCancel(ctx context.Context, mode Mode, events <-chan domain.Event, actions chan<- domain.Action, meta Meta, cancel func()) error {
	m := NewModel(ctx, mode, events, actions, meta, cancel)

	in := os.Stdin
	out := os.Stdout
	var tty *os.File

	// If we're launched through a wrapper that doesn't preserve TTY stdio (e.g., a PHP
	// bootstrapper), Bubble Tea might not be able to detect the real window size. When
	// possible, attach directly to /dev/tty.
	if runtime.GOOS != "windows" && (!term.IsTerminal(in.Fd()) || !term.IsTerminal(out.Fd())) {
		f, err := os.OpenFile("/dev/tty", os.O_RDWR, 0)
		if err == nil {
			tty = f
			in = f
			out = f
		}
	}
	if tty != nil {
		defer tty.Close()
	}

	// Seed a sensible initial size so the UI can render even if WindowSizeMsg never arrives.
	if w, h, ok := detectTerminalSize(in, out, tty); ok {
		m.width = w
		m.height = h
	} else {
		m.width = 80
		m.height = 24
	}
	m.reflow()

	p := tea.NewProgram(m, tea.WithAltScreen(), tea.WithInput(in), tea.WithOutput(out))
	_, err := p.Run()
	return err
}

func detectTerminalSize(in *os.File, out *os.File, tty *os.File) (w int, h int, ok bool) {
	fds := []uintptr{}
	if out != nil {
		fds = append(fds, out.Fd())
	}
	if in != nil {
		fds = append(fds, in.Fd())
	}
	if tty != nil {
		fds = append(fds, tty.Fd())
	}
	fds = append(fds, os.Stderr.Fd())

	for _, fd := range fds {
		if fd == 0 || !term.IsTerminal(fd) {
			continue
		}
		if tw, th, err := term.GetSize(fd); err == nil && tw > 0 && th > 0 {
			return tw, th, true
		}
	}

	if cols, err := strconv.Atoi(os.Getenv("COLUMNS")); err == nil && cols > 0 {
		if lines, err := strconv.Atoi(os.Getenv("LINES")); err == nil && lines > 0 {
			return cols, lines, true
		}
	}

	return 0, 0, false
}
