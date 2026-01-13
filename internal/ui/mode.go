package ui

import "github.com/evolution-cms/installer/internal/domain"

type Mode string

const (
	ModeInstall Mode = "install"
	ModeDoctor  Mode = "doctor"
)

func (m Mode) DomainMode() domain.AppMode {
	switch m {
	case ModeDoctor:
		return domain.ModeDoctor
	default:
		return domain.ModeInstall
	}
}
