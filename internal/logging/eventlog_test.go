package logging

import (
	"testing"

	"github.com/evolution-cms/installer/internal/domain"
)

func TestFinalizeWritesLogWhenStepDoneFails(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	logger := NewEventLogger(Config{InstallDir: dir})
	logger.Record(domain.Event{
		Type:   domain.EventStepDone,
		StepID: "extras",
		Payload: domain.StepDonePayload{
			OK: false,
		},
	})

	res, err := logger.Finalize()
	if err != nil {
		t.Fatalf("Finalize error: %v", err)
	}
	if !res.Written {
		t.Fatalf("expected log to be written for failed step")
	}
}
