package domain

import "time"

func NormalizeSystemStatus(s SystemStatus) SystemStatus {
	s.Overall = ComputeOverallLevel(s.Items)
	if s.OverallLabel == "" {
		s.OverallLabel = OverallLabel(s.Overall)
	}
	if s.UpdatedAt.IsZero() {
		s.UpdatedAt = time.Now()
	}
	return s
}

func ComputeOverallLevel(items []StatusItem) StatusLevel {
	overall := StatusOK
	for _, it := range items {
		if it.Level == StatusError {
			return StatusError
		}
		if it.Level == StatusWarn {
			overall = StatusWarn
		}
	}
	return overall
}

func OverallLabel(level StatusLevel) string {
	switch level {
	case StatusError:
		return "Errors"
	case StatusWarn:
		return "Warnings"
	default:
		return "OK"
	}
}

