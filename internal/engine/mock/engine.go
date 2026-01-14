package mock

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"math/rand"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
	"github.com/evolution-cms/installer/internal/services/release"
)

type Engine struct {
	rng *rand.Rand
}

func New() *Engine {
	return &Engine{
		rng: rand.New(rand.NewSource(time.Now().UnixNano())),
	}
}

func (e *Engine) Run(ctx context.Context, ch chan<- domain.Event, _ <-chan domain.Action) {
	go func() {
		defer close(ch)

		speed := envInt("EVO_MOCK_SPEED", 1)
		if speed < 1 {
			speed = 1
		}
		sleep := func(d time.Duration) bool {
			d = d / time.Duration(speed)
			t := time.NewTimer(d)
			defer t.Stop()
			select {
			case <-ctx.Done():
				return false
			case <-t.C:
				return true
			}
		}

		failStep := envInt("EVO_MOCK_FAIL_STEP", 0)

		emit := func(ev domain.Event) bool {
			if ev.TS.IsZero() {
				ev.TS = time.Now()
			}
			select {
			case <-ctx.Done():
				return false
			case ch <- ev:
				return true
			}
		}

		// Send a deterministic steps plan for Quest track (no UI demo defaults).
		_ = emit(domain.Event{
			Type:     domain.EventSteps,
			StepID:   "",
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.StepsPayload{
				Steps: []domain.StepState{
					{ID: "php", Label: "Step 1: Validate PHP version", Status: domain.StepPending},
					{ID: "database", Label: "Step 2: Check database connection", Status: domain.StepPending},
					{ID: "download", Label: "Step 3: Download Evolution CMS", Status: domain.StepPending},
					{ID: "install", Label: "Step 4: Install Evolution CMS", Status: domain.StepPending},
					{ID: "presets", Label: "Step 5: Install presets", Status: domain.StepPending},
					{ID: "dependencies", Label: "Step 6: Install dependencies", Status: domain.StepPending},
					{ID: "finalize", Label: "Step 7: Finalize installation", Status: domain.StepPending},
				},
			},
		})

		// Step: detect highest stable release version (by semver).
		const releaseStepID = "fetch_release_version"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   releaseStepID,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Detect latest stable version (by semver)",
				Index: 0,
				Total: 0,
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   releaseStepID,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Fetching releases…",
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventProgress,
			StepID:   releaseStepID,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.ProgressPayload{
				Current: 0,
				Total:   100,
				Unit:    "pct",
			},
		})

		releaseInfo, cached, err := release.DetectHighestStable(ctx, "evolution-cms", "evolution", release.DetectOptions{
			MaxPages: 3,
			CacheTTL: time.Hour,
			OnPageFetched: func(page int) {
				if page == 1 {
					_ = emit(domain.Event{
						Type:     domain.EventProgress,
						StepID:   releaseStepID,
						Source:   "mock",
						Severity: domain.SeverityInfo,
						Payload: domain.ProgressPayload{
							Current: 50,
							Total:   100,
							Unit:    "pct",
						},
					})
				}
			},
		})
		if err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventWarning,
				StepID:   releaseStepID,
				Source:   "mock",
				Severity: domain.SeverityWarn,
				Payload: domain.LogPayload{
					Message: "Unable to fetch release info; continuing…",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   releaseStepID,
				Source:   "mock",
				Severity: domain.SeverityWarn,
				Payload:  domain.StepDonePayload{OK: false},
			})
		} else {
			tag := releaseInfo.Tag
			if tag == "" && releaseInfo.HighestVersion != "" {
				tag = "v" + releaseInfo.HighestVersion
			}
			msg := "Highest stable release: " + tag
			if cached {
				msg += " (cached)"
			}
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   releaseStepID,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: msg,
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventProgress,
				StepID:   releaseStepID,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload: domain.ProgressPayload{
					Current: 100,
					Total:   100,
					Unit:    "pct",
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   releaseStepID,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload:  releaseInfo,
			})
		}

		// Step: check system status via PHP adapter.
		const sysStepID = "check_system_status"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   sysStepID,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Check system status",
				Index: 0,
				Total: 0,
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   sysStepID,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Checking system status…",
			},
		})

		status, err := fetchSystemStatus(ctx)
		if err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventWarning,
				StepID:   sysStepID,
				Source:   "mock",
				Severity: domain.SeverityWarn,
				Payload: domain.LogPayload{
					Message: "Unable to check system status; continuing…",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventSystemStatus,
				StepID:   sysStepID,
				Source:   "mock",
				Severity: domain.SeverityWarn,
				Payload: domain.SystemStatus{
					Items:     nil,
					UpdatedAt: time.Now(),
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   sysStepID,
				Source:   "mock",
				Severity: domain.SeverityWarn,
				Payload:  domain.StepDonePayload{OK: false},
			})
		} else {
			_ = emit(domain.Event{
				Type:     domain.EventSystemStatus,
				StepID:   sysStepID,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload:  status,
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   sysStepID,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload:  domain.StepDonePayload{OK: true},
			})
		}

		// A sample question to drive UI selection (engine does not consume the answer yet).
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			Source:   "mock",
			Severity: domain.SeverityInfo,
			Payload: domain.QuestionPayload{
				Question: domain.QuestionState{
					Active: true,
					ID:     "db_driver",
					Prompt: "Which database driver do you want to use?",
					Options: []domain.QuestionOption{
						{ID: "mysql", Label: "MySQL or MariaDB", Enabled: true},
						{ID: "pgsql", Label: "PostgreSQL", Enabled: true},
						{ID: "sqlite", Label: "SQLite", Enabled: true},
						{ID: "sqlsrv", Label: "SQL Server", Enabled: true, Reason: "Driver may be missing"},
					},
					Selected: 0,
				},
			},
		})

		steps := []struct {
			id    string
			label string
		}{
			{"php", "Step 1: Validate PHP version"},
			{"database", "Step 2: Check database connection"},
			{"download", "Step 3: Download Evolution CMS"},
			{"install", "Step 4: Install Evolution CMS"},
			{"presets", "Step 5: Install presets"},
			{"dependencies", "Step 6: Install dependencies"},
			{"finalize", "Step 7: Finalize installation"},
		}

		for i, s := range steps {
			if failStep > 0 && failStep == i+1 {
				_ = emit(domain.Event{
					Type:     domain.EventError,
					StepID:   s.id,
					Source:   "mock",
					Severity: domain.SeverityError,
					Payload: domain.LogPayload{
						Message: "Simulated failure on step " + strconv.Itoa(i+1),
					},
				})
				return
			}

			_ = emit(domain.Event{
				Type:     domain.EventStepStart,
				StepID:   s.id,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload: domain.StepStartPayload{
					Label: s.label,
					Index: i + 1,
					Total: len(steps),
				},
			})

			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   s.id,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: s.label + "…",
				},
			})

			// Progress simulation.
			total := int64(100)
			cur := int64(0)
			for cur < total {
				if !sleep(120 * time.Millisecond) {
					return
				}
				cur += int64(10 + e.rng.Intn(12))
				if cur > total {
					cur = total
				}
				_ = emit(domain.Event{
					Type:     domain.EventProgress,
					StepID:   s.id,
					Source:   "mock",
					Severity: domain.SeverityInfo,
					Payload: domain.ProgressPayload{
						Current: cur,
						Total:   total,
						Unit:    "pct",
					},
				})

				if e.rng.Intn(25) == 0 {
					_ = emit(domain.Event{
						Type:     domain.EventWarning,
						StepID:   s.id,
						Source:   "mock",
						Severity: domain.SeverityWarn,
						Payload: domain.LogPayload{
							Message: "Non-critical warning (simulated)",
						},
					})
				}
			}

			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   s.id,
				Source:   "mock",
				Severity: domain.SeverityInfo,
				Payload:  domain.StepDonePayload{OK: true},
			})
		}
	}()
}

func envInt(key string, def int) int {
	raw := strings.TrimSpace(os.Getenv(key))
	if raw == "" {
		return def
	}
	n, err := strconv.Atoi(raw)
	if err != nil {
		return def
	}
	return n
}

type systemStatusJSON struct {
	Overall string `json:"overall"`
	Items   []struct {
		Key     string `json:"key"`
		Label   string `json:"label"`
		Level   string `json:"level"`
		Details string `json:"details,omitempty"`
	} `json:"items"`
}

func fetchSystemStatus(ctx context.Context) (domain.SystemStatus, error) {
	entry, err := findPHPInstallerCLIEntry()
	if err != nil {
		return domain.SystemStatus{}, err
	}

	ctx, cancel := context.WithTimeout(ctx, 25*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, "php", entry, "system-status", "--format=json", "--no-ansi", "--no-interaction")
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	out, execErr := cmd.Output()

	var parsed systemStatusJSON
	if unmarshalErr := json.Unmarshal(out, &parsed); unmarshalErr != nil {
		if execErr != nil {
			if stderr.Len() > 0 {
				return domain.SystemStatus{}, fmt.Errorf("%w: %s", execErr, strings.TrimSpace(stderr.String()))
			}
			return domain.SystemStatus{}, execErr
		}
		return domain.SystemStatus{}, unmarshalErr
	}

	items := make([]domain.StatusItem, 0, len(parsed.Items))
	for _, it := range parsed.Items {
		level := domain.StatusOK
		switch strings.ToLower(strings.TrimSpace(it.Level)) {
		case "warn", "warning":
			level = domain.StatusWarn
		case "error", "err":
			level = domain.StatusError
		}
		items = append(items, domain.StatusItem{
			Key:     it.Key,
			Label:   it.Label,
			Level:   level,
			Details: it.Details,
		})
	}

	status := domain.SystemStatus{
		Items:     items,
		UpdatedAt: time.Now(),
	}

	switch strings.ToLower(strings.TrimSpace(parsed.Overall)) {
	case "warn", "warning":
		status.Overall = domain.StatusWarn
	case "error", "err":
		status.Overall = domain.StatusError
	case "ok":
		status.Overall = domain.StatusOK
	default:
		status.Overall = domain.ComputeOverallLevel(items)
	}
	return domain.NormalizeSystemStatus(status), nil
}

func findPHPInstallerCLIEntry() (string, error) {
	candidates := []string{}

	// Prefer the bootstrapper on PATH (installed alongside the Go binary).
	if p, err := exec.LookPath("evo"); err == nil && p != "" {
		candidates = append(candidates, p)
	}

	// Prefer a sibling `evo` script next to the running executable (common install layout).
	if exe, err := os.Executable(); err == nil && exe != "" {
		exeDir := filepath.Dir(exe)
		if exeDir != "" && exeDir != "." {
			candidates = append(candidates, filepath.Join(exeDir, "evo"))
		}
	}

	// Repo-local fallbacks (when running from source checkout).
	candidates = append(candidates,
		filepath.Join("installer", "bin", "evo"),
		filepath.Join("bin", "evo"),
	)
	for _, p := range candidates {
		if strings.TrimSpace(p) == "" {
			continue
		}
		fi, err := os.Stat(p)
		if err != nil || fi.IsDir() {
			continue
		}
		if !looksLikePHPScript(p) {
			continue
		}
		return p, nil
	}
	return "", fmt.Errorf("unable to find installer PHP CLI entry (tried: %s)", strings.Join(candidates, ", "))
}

func looksLikePHPScript(path string) bool {
	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()

	buf := make([]byte, 256)
	n, _ := f.Read(buf)
	if n <= 0 {
		return false
	}
	head := strings.ToLower(string(buf[:n]))
	if strings.Contains(head, "<?php") {
		return true
	}
	if strings.HasPrefix(head, "#!") && strings.Contains(head, "php") {
		return true
	}
	return false
}
