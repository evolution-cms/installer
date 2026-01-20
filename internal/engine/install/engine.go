package install

import (
	"bufio"
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/mail"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
	"github.com/evolution-cms/installer/internal/services/github"
	"github.com/evolution-cms/installer/internal/services/release"
)

type Options struct {
	Force bool
	Dir   string

	SelfVersion string

	Branch             string
	ComposerClearCache bool
	ComposerUpdate     bool

	DBType     string
	DBHost     string
	DBPort     int
	DBName     string
	DBUser     string
	DBPassword string

	AdminUsername  string
	AdminEmail     string
	AdminPassword  string
	AdminDirectory string
	Language       string

	GithubPat string
	Extras    []domain.ExtrasSelection
}

type Engine struct {
	opt Options
}

func New(opt Options) *Engine { return &Engine{opt: opt} }

func (e *Engine) Run(ctx context.Context, ch chan<- domain.Event, actions <-chan domain.Action) {
	go func() {
		defer close(ch)

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

		var sysStatus domain.SystemStatus

		// Quest track plan (source of truth: InstallCommand::$steps).
		_ = emit(domain.Event{
			Type:     domain.EventSteps,
			Source:   "install",
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
					{ID: "extras", Label: "Step 8: Install Extras (optional)", Status: domain.StepPending},
				},
			},
		})

		// Internal startup: detect highest stable release version (by semver).
		const releaseStepID = "fetch_release_version"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   releaseStepID,
			Source:   "install",
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
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Fetching releases…",
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventProgress,
			StepID:   releaseStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.ProgressPayload{
				Current: 0,
				Total:   100,
				Unit:    "pct",
			},
		})

		releaseInfo, _, err := release.DetectHighestStable(ctx, "evolution-cms", "evolution", release.DetectOptions{
			MaxPages: 3,
			CacheTTL: time.Hour,
			OnPageFetched: func(page int) {
				if page == 1 {
					_ = emit(domain.Event{
						Type:     domain.EventProgress,
						StepID:   releaseStepID,
						Source:   "install",
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
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload: domain.LogPayload{
					Message: "Unable to fetch release info; continuing…",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   releaseStepID,
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload:  domain.StepDonePayload{OK: false},
			})
		} else {
			tag := releaseInfo.Tag
			if tag == "" && releaseInfo.HighestVersion != "" {
				tag = "v" + releaseInfo.HighestVersion
			}
			msg := "Highest stable release: " + tag
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   releaseStepID,
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: msg,
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventProgress,
				StepID:   releaseStepID,
				Source:   "install",
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
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload:  releaseInfo,
			})
		}

		// Internal startup: check system status via PHP adapter.
		const sysStepID = "check_system_status"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   sysStepID,
			Source:   "install",
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
			Source:   "install",
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
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload: domain.LogPayload{
					Message: "Unable to check system status; continuing…",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventSystemStatus,
				StepID:   sysStepID,
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload: domain.SystemStatus{
					Items:     nil,
					UpdatedAt: time.Now(),
				},
			})
			sysStatus = domain.SystemStatus{}
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   sysStepID,
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload:  domain.StepDonePayload{OK: false},
			})
		} else {
			_ = emit(domain.Event{
				Type:     domain.EventSystemStatus,
				StepID:   sysStepID,
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload:  status,
			})
			sysStatus = status
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   sysStepID,
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload:  domain.StepDonePayload{OK: true},
			})
		}

		if e.maybeOfferSelfUpdate(ctx, emit, actions) {
			return
		}

		workDir := strings.TrimSpace(e.opt.Dir)
		if workDir == "" {
			workDir = "."
		}
		workDir = filepath.Clean(workDir)
		if err := os.MkdirAll(workDir, 0o755); err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventError,
				StepID:   "preflight",
				Source:   "install",
				Severity: domain.SeverityError,
				Payload: domain.LogPayload{
					Message: "Unable to prepare installation directory.",
					Fields:  map[string]string{"error": err.Error(), "dir": workDir},
				},
			})
			return
		}

		// Preflight: prevent installing over an existing instance unless --force.
		// Do this after startup probes so the UI can render header + system status normally.
		if !e.opt.Force {
			if ok, marker := detectExistingEvoInstall(workDir); ok {
				_ = emit(domain.Event{
					Type:     domain.EventError,
					StepID:   "preflight",
					Source:   "install",
					Severity: domain.SeverityError,
					Payload: domain.LogPayload{
						Message: "Existing Evolution CMS installation detected (" + marker + ") in " + workDir + ". Re-run with -f/--force to install anyway.",
					},
				})
				return
			}
		}

		// Step 1: Validate PHP version (real check).
		const phpStepID = "php"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   phpStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Step 1: Validate PHP version",
				Index: 1,
				Total: 8,
			},
		})

		phpVersion, ok, err := validatePHPVersion(ctx)
		if err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventError,
				StepID:   phpStepID,
				Source:   "install",
				Severity: domain.SeverityError,
				Payload: domain.LogPayload{
					Message: "Unable to detect PHP version.",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   phpStepID,
				Source:   "install",
				Severity: domain.SeverityError,
				Payload:  domain.StepDonePayload{OK: false},
			})
			return
		}
		if !ok {
			_ = emit(domain.Event{
				Type:     domain.EventError,
				StepID:   phpStepID,
				Source:   "install",
				Severity: domain.SeverityError,
				Payload: domain.LogPayload{
					Message: fmt.Sprintf("PHP version %s is not supported (requires >= 8.3.0).", phpVersion),
				},
			})
			_ = emit(domain.Event{
				Type:     domain.EventStepDone,
				StepID:   phpStepID,
				Source:   "install",
				Severity: domain.SeverityError,
				Payload:  domain.StepDonePayload{OK: false},
			})
			return
		}

		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   phpStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: fmt.Sprintf("✔ PHP version %s is supported.", phpVersion),
			},
		})
		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   phpStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload:  domain.StepDonePayload{OK: true},
		})

		// Step 2: Database connection (start with DB driver selection).
		const dbStepID = "database"
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Step 2: Check database connection",
				Index: 2,
				Total: 8,
			},
		})

		var (
			dbType     string
			dbHost     string
			dbPort     int
			dbName     string
			dbUser     string
			dbPassword string
		)

		for {
			opts := dbDriverQuestionOptions(sysStatus)
			enabled := enabledOptionIndexes(opts)
			if len(enabled) == 0 {
				_ = emit(domain.Event{
					Type:     domain.EventError,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityError,
					Payload: domain.LogPayload{
						Message: "No supported PDO database drivers are available. Please install one of: pdo_mysql, pdo_pgsql, pdo_sqlite, pdo_sqlsrv.",
					},
				})
				_ = emit(domain.Event{
					Type:     domain.EventStepDone,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityError,
					Payload:  domain.StepDonePayload{OK: false},
				})
				return
			}

			var (
				selectedDriver string
				okSel          bool
			)

			requestedDriver := strings.ToLower(strings.TrimSpace(e.opt.DBType))
			if requestedDriver != "" {
				requestedIdx := -1
				for i := range opts {
					if opts[i].ID == requestedDriver {
						requestedIdx = i
						break
					}
				}
				if requestedIdx >= 0 && opts[requestedIdx].Enabled {
					selectedDriver = requestedDriver
					okSel = true
				} else if requestedIdx >= 0 && !opts[requestedIdx].Enabled {
					reason := strings.TrimSpace(opts[requestedIdx].Reason)
					if reason == "" {
						reason = "not available"
					}
					_ = emit(domain.Event{
						Type:     domain.EventWarning,
						StepID:   dbStepID,
						Source:   "install",
						Severity: domain.SeverityWarn,
						Payload: domain.LogPayload{
							Message: "Requested database driver '" + requestedDriver + "' cannot be used: " + reason + ".",
						},
					})
				} else {
					_ = emit(domain.Event{
						Type:     domain.EventWarning,
						StepID:   dbStepID,
						Source:   "install",
						Severity: domain.SeverityWarn,
						Payload: domain.LogPayload{
							Message: "Unknown database driver requested: '" + requestedDriver + "'.",
						},
					})
				}
			}

			if okSel {
				// Selected from CLI defaults above.
			} else if len(enabled) == 1 {
				selectedDriver = opts[enabled[0]].ID
				okSel = true
			} else {
				selectedDriver, okSel = askSelect(ctx, emit, actions, dbStepID, domain.QuestionState{
					Active:   true,
					ID:       "db_driver",
					Kind:     domain.QuestionSelect,
					Prompt:   "Which database driver do you want to use?",
					Options:  opts,
					Selected: enabled[0],
				})
			}
			if !okSel {
				return
			}
			dbType = selectedDriver
			driverLabel := dbDriverLabel(dbType)
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   dbStepID,
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: "Selected database driver: " + driverLabel + ".",
				},
			})

			switch dbType {
			case "sqlite":
				dbName = strings.TrimSpace(e.opt.DBName)
				if dbName == "" {
					dbName, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
						Active:  true,
						ID:      "db_sqlite_path",
						Kind:    domain.QuestionInput,
						Prompt:  "What is the path to your SQLite database file?",
						Default: "database.sqlite",
					})
					if !ok {
						return
					}
				}
				dbHost, dbUser, dbPassword = "", "", ""
				dbPort = 0
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "Selected database path: " + dbName + ".",
					},
				})
			default:
				dbHost = strings.TrimSpace(e.opt.DBHost)
				if dbHost == "" {
					dbHost, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
						Active:  true,
						ID:      "db_host",
						Kind:    domain.QuestionInput,
						Prompt:  "Where is your database server located?",
						Default: "localhost",
					})
					if !ok {
						return
					}
				}
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "Selected database host: " + dbHost + ".",
					},
				})

				dbName = strings.TrimSpace(e.opt.DBName)
				if dbName == "" {
					dbName, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
						Active:  true,
						ID:      "db_name",
						Kind:    domain.QuestionInput,
						Prompt:  "What is your database name?",
						Default: "evo_db",
					})
					if !ok {
						return
					}
				}
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "Selected database name: " + dbName + ".",
					},
				})

				dbUser = strings.TrimSpace(e.opt.DBUser)
				if dbUser == "" {
					dbUser, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
						Active:  true,
						ID:      "db_user",
						Kind:    domain.QuestionInput,
						Prompt:  "What is your database username?",
						Default: "root",
					})
					if !ok {
						return
					}
				}
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "Selected database user: " + dbUser + ".",
					},
				})

				dbPassword = e.opt.DBPassword
				if dbPassword == "" {
					dbPassword, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
						Active:  true,
						ID:      "db_password",
						Kind:    domain.QuestionInput,
						Prompt:  "What is your database password?",
						Default: "",
						Secret:  true,
					})
					if !ok {
						return
					}
				}
				pwLabel := "(empty)"
				if strings.TrimSpace(dbPassword) != "" {
					pwLabel = "••••••••"
				}
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "Selected database password: " + pwLabel + ".",
					},
				})

				dbPort = defaultPort(dbType)
				if e.opt.DBPort > 0 {
					dbPort = e.opt.DBPort
				}
			}

			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   dbStepID,
				Source:   "install",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: "Testing database connection...",
				},
			})

			okConn, msg, err := testDatabaseConnection(ctx, workDir, dbConfig{
				Type:     dbType,
				Host:     dbHost,
				Port:     dbPort,
				Name:     dbName,
				User:     dbUser,
				Password: dbPassword,
			})
			if err != nil {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Database connection check failed unexpectedly.",
						Fields:  map[string]string{"error": err.Error()},
					},
				})
				return
			}
			if okConn {
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: "✔ Database connection successful!",
						Fields:  map[string]string{"op": "replace_last"},
					},
				})
				break
			}

			_ = emit(domain.Event{
				Type:     domain.EventWarning,
				StepID:   dbStepID,
				Source:   "install",
				Severity: domain.SeverityWarn,
				Payload: domain.LogPayload{
					Message: "Database connection failed: " + msg,
				},
			})

			promptMsg := strings.TrimSpace(msg)
			promptMsg = strings.ReplaceAll(promptMsg, "\r", " ")
			promptMsg = strings.ReplaceAll(promptMsg, "\n", " ")
			promptMsg = strings.Join(strings.Fields(promptMsg), " ")
			if len(promptMsg) > 160 {
				promptMsg = promptMsg[:160] + "..."
			}

			retry, ok := askSelect(ctx, emit, actions, dbStepID, domain.QuestionState{
				Active: true,
				ID:     "db_retry",
				Kind:   domain.QuestionSelect,
				Prompt: "Database connection failed: " + promptMsg + " — try again or exit installation?",
				Options: []domain.QuestionOption{
					{ID: "exit", Label: "Exit installation", Enabled: true},
					{ID: "retry", Label: "Try again", Enabled: true},
				},
				Selected: 1,
			})
			if !ok {
				return
			}
			if retry == "exit" {
				_ = emit(domain.Event{
					Type:     domain.EventError,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityError,
					Payload: domain.LogPayload{
						Message: "Installation cancelled by user.",
					},
				})
				_ = emit(domain.Event{
					Type:     domain.EventStepDone,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityError,
					Payload:  domain.StepDonePayload{OK: false},
				})
				return
			}
		}

		// Admin + language questions (still part of gatherInputs() before continuing).
		adminUser := strings.TrimSpace(e.opt.AdminUsername)
		if adminUser == "" {
			adminUser, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
				Active:  true,
				ID:      "admin_username",
				Kind:    domain.QuestionInput,
				Prompt:  "Enter your Admin username:",
				Default: "admin",
			})
			if !ok {
				return
			}
		}
		if strings.TrimSpace(adminUser) == "" {
			adminUser = "admin"
		}
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Your Admin username: " + adminUser + ".",
			},
		})

		var adminEmail string
		adminEmailTried := false
		for {
			if !adminEmailTried && strings.TrimSpace(e.opt.AdminEmail) != "" && adminEmail == "" {
				adminEmailTried = true
				email := strings.TrimSpace(e.opt.AdminEmail)
				_, parseErr := mail.ParseAddress(email)
				if parseErr == nil {
					adminEmail = email
					break
				}
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Provided --admin-email is invalid; please enter it again.",
						Fields:  map[string]string{"error": parseErr.Error()},
					},
				})
			}
			email, ok := askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
				Active: true,
				ID:     "admin_email",
				Kind:   domain.QuestionInput,
				Prompt: "Enter your Admin email:",
			})
			if !ok {
				return
			}
			email = strings.TrimSpace(email)
			if email == "" {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Email address cannot be empty. Please try again.",
					},
				})
				continue
			}
			if _, err := mail.ParseAddress(email); err != nil {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Please enter a valid email address. Try again.",
					},
				})
				continue
			}
			adminEmail = email
			break
		}
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Your Admin email: " + adminEmail + ".",
			},
		})

		var adminPass string
		adminPassTried := false
		for {
			pwOpt := strings.TrimSpace(e.opt.AdminPassword)
			if !adminPassTried && adminPass == "" && pwOpt != "" {
				adminPassTried = true
				if len([]rune(pwOpt)) >= 6 {
					adminPass = pwOpt
					break
				}
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Provided --admin-password is too short; please enter it again.",
					},
				})
			}
			pw, ok := askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
				Active: true,
				ID:     "admin_password",
				Kind:   domain.QuestionInput,
				Prompt: "Enter your Admin password:",
				Secret: true,
			})
			if !ok {
				return
			}
			pw = strings.TrimSpace(pw)
			if pw == "" {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Password cannot be empty. Please try again.",
					},
				})
				continue
			}
			if len([]rune(pw)) < 6 {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   dbStepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Password must be at least 6 characters long. Try again.",
					},
				})
				continue
			}
			adminPass = pw
			break
		}
		_ = adminPass
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Your Admin password: ••••••••.",
			},
		})

		adminDir := strings.TrimSpace(e.opt.AdminDirectory)
		if adminDir == "" {
			adminDir, ok = askInput(ctx, emit, actions, dbStepID, domain.QuestionState{
				Active:  true,
				ID:      "admin_directory",
				Kind:    domain.QuestionInput,
				Prompt:  "Enter your Admin directory:",
				Default: "manager",
			})
			if !ok {
				return
			}
		}
		adminDir = sanitizeAdminDir(adminDir)
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Your Admin directory: " + adminDir + ".",
			},
		})

		lang := strings.ToLower(strings.TrimSpace(e.opt.Language))
		if lang == "" {
			lang, ok = askSelect(ctx, emit, actions, dbStepID, languageQuestion())
			if !ok {
				return
			}
		}
		langLabel := languageLabel(lang)
		_ = emit(domain.Event{
			Type:     domain.EventLog,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.LogPayload{
				Message: "Selected language: " + langLabel + ".",
			},
		})

		_ = emit(domain.Event{
			Type:     domain.EventStepDone,
			StepID:   dbStepID,
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload:  domain.StepDonePayload{OK: true},
		})

		// Step 3+: follow InstallCommand pipeline (next).
		_ = emit(domain.Event{
			Type:     domain.EventStepStart,
			StepID:   "download",
			Source:   "install",
			Severity: domain.SeverityInfo,
			Payload: domain.StepStartPayload{
				Label: "Step 3: Download Evolution CMS",
				Index: 3,
				Total: 8,
			},
		})

		if err := runPHPNewCommand(ctx, emit, phpNewOptions{
			DBType:             dbType,
			DBHost:             dbHost,
			DBPort:             dbPort,
			DBName:             dbName,
			DBUser:             dbUser,
			DBPassword:         dbPassword,
			AdminUsername:      adminUser,
			AdminEmail:         adminEmail,
			AdminPassword:      adminPass,
			AdminDirectory:     adminDir,
			Language:           lang,
			Force:              e.opt.Force,
			Branch:             strings.TrimSpace(e.opt.Branch),
			WorkDir:            workDir,
			ComposerClearCache: e.opt.ComposerClearCache,
			ComposerUpdate:     e.opt.ComposerUpdate,
			GithubPat:          strings.TrimSpace(e.opt.GithubPat),
			Extras:             e.opt.Extras,
		}); err != nil {
			_ = emit(domain.Event{
				Type:     domain.EventError,
				StepID:   "download",
				Source:   "install",
				Severity: domain.SeverityError,
				Payload: domain.LogPayload{
					Message: "Installation failed.",
					Fields:  map[string]string{"error": err.Error()},
				},
			})
			return
		}

		e.maybeRunExtras(ctx, emit, actions, workDir)
	}()
}

type phpNewOptions struct {
	DBType     string
	DBHost     string
	DBPort     int
	DBName     string
	DBUser     string
	DBPassword string

	AdminUsername  string
	AdminEmail     string
	AdminPassword  string
	AdminDirectory string
	Language       string

	GithubPat string
	Extras    []domain.ExtrasSelection

	Force   bool
	Branch  string
	WorkDir string

	ComposerClearCache bool
	ComposerUpdate     bool
}

var consoleTagRe = regexp.MustCompile(`<[^>]+>`)
var plainProgressLineRe = regexp.MustCompile(`^([A-Za-z][A-Za-z ]+)\s+\[[^\]]+\]\s+(\d{1,3})%\s*(\([^)]*\))?\s*$`)
var adminDirSanitizeRe = regexp.MustCompile(`[^a-zA-Z0-9_-]+`)

func stripConsoleTags(s string) string {
	return consoleTagRe.ReplaceAllString(s, "")
}

func parsePlainProgressLine(line string) (label string, pct int, tail string, ok bool) {
	m := plainProgressLineRe.FindStringSubmatch(strings.TrimSpace(line))
	if m == nil {
		return "", 0, "", false
	}
	label = strings.TrimSpace(m[1])
	if label == "" {
		return "", 0, "", false
	}
	if _, err := fmt.Sscanf(m[2], "%d", &pct); err != nil {
		return "", 0, "", false
	}
	if pct < 0 {
		pct = 0
	}
	if pct > 100 {
		pct = 100
	}
	tail = strings.TrimSpace(m[3])
	return label, pct, tail, true
}

func sanitizeAdminDir(s string) string {
	s = strings.TrimSpace(s)
	if s == "" {
		return "manager"
	}
	s = adminDirSanitizeRe.ReplaceAllString(s, "")
	if s == "" {
		return "manager"
	}
	return s
}

func shouldSuppressPHPSubprocessLine(line string) bool {
	line = strings.TrimSpace(line)
	if line == "" {
		return false
	}
	// The Go installer already reports this once during Step 1.
	if strings.HasPrefix(line, "✔ PHP version ") && strings.Contains(line, " is supported.") {
		return true
	}
	if strings.HasPrefix(line, "PHP version ") && strings.Contains(line, " is supported.") {
		return true
	}
	// Too noisy during each seeder run.
	if strings.HasPrefix(line, "INFO") && strings.Contains(line, "Seeding database.") {
		return true
	}
	return false
}

func runPHPNewCommand(ctx context.Context, emit func(domain.Event) bool, opt phpNewOptions) error {
	tracker := newStepTracker(emit)

	entry, err := findPHPSymfonyCLIEntry()
	if err != nil {
		tracker.FailRemaining()
		return err
	}

	args := []string{
		entry,
		"install",
		".",
		"--no-ansi",
		"--no-interaction",
		"--db-type=" + opt.DBType,
		"--db-host=" + opt.DBHost,
		fmt.Sprintf("--db-port=%d", opt.DBPort),
		"--db-name=" + opt.DBName,
		"--db-user=" + opt.DBUser,
		"--db-password=" + opt.DBPassword,
		"--admin-username=" + opt.AdminUsername,
		"--admin-email=" + opt.AdminEmail,
		"--admin-password=" + opt.AdminPassword,
		"--admin-directory=" + opt.AdminDirectory,
		"--language=" + opt.Language,
	}
	if strings.TrimSpace(opt.Branch) != "" {
		args = append(args, "--branch="+strings.TrimSpace(opt.Branch))
	}
	if strings.TrimSpace(opt.GithubPat) != "" {
		args = append(args, "--github-pat="+strings.TrimSpace(opt.GithubPat))
	}
	if opt.Force {
		args = append(args, "--force")
	}
	if opt.ComposerUpdate {
		args = append(args, "--composer-update")
	}
	if opt.ComposerClearCache {
		args = append(args, "--composer-clear-cache")
	}

	runCtx, cancel := context.WithCancel(ctx)
	defer cancel()

	cmd := exec.CommandContext(runCtx, "php", args...)
	if strings.TrimSpace(opt.WorkDir) != "" {
		cmd.Dir = opt.WorkDir
	}
	env := append([]string(nil), os.Environ()...)
	env = append(env, "CI=1")
	// Some older PHP installer versions connect to PostgreSQL without dbname to validate
	// credentials. PostgreSQL defaults dbname to the username in that case. Setting
	// PGDATABASE ensures those connection attempts use a maintenance database instead.
	if strings.EqualFold(strings.TrimSpace(opt.DBType), "pgsql") {
		env = append(env, "PGDATABASE=template1")
	}
	cmd.Env = env

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		tracker.FailRemaining()
		return err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		tracker.FailRemaining()
		return err
	}
	if err := cmd.Start(); err != nil {
		tracker.FailRemaining()
		return err
	}

	type subprocessLine struct {
		text   string
		stderr bool
	}

	linesCh := make(chan subprocessLine, 256)
	var wg sync.WaitGroup
	readPipe := func(r io.Reader, isStderr bool) {
		defer wg.Done()
		sc := bufio.NewScanner(r)
		sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
		for sc.Scan() {
			line := strings.TrimSpace(stripConsoleTags(sc.Text()))
			if line == "" {
				continue
			}
			linesCh <- subprocessLine{text: line, stderr: isStderr}
		}
	}

	wg.Add(2)
	go readPipe(stdout, false)
	go readPipe(stderr, true)
	go func() {
		wg.Wait()
		close(linesCh)
	}()

	lastWasSeederStart := false
	lastSeeder := ""
	lastStep := ""
	lastPlainLine := ""
	lastPlainStepID := ""
	lastPlainWasStderr := false

	for l := range linesCh {
		line := l.text
		tracker.OnLine(line)
		if tracker.HasFailed() {
			// Abort immediately when a step is marked failed (e.g., download failed)
			// even if the PHP command would otherwise continue.
			cancel()
		}
		stepID := tracker.CurrentStepID()

		if shouldSuppressPHPSubprocessLine(line) {
			continue
		}

		if seeder, ok := parseSeederStartLine(line); ok {
			lastWasSeederStart = true
			lastSeeder = seeder
			lastStep = stepID
		}

		if seeder, ok := parseSeederDoneLine(line); ok {
			if lastWasSeederStart && lastSeeder == seeder && lastStep == stepID {
				_ = emit(domain.Event{
					Type:     domain.EventLog,
					StepID:   stepID,
					Source:   "php",
					Severity: domain.SeverityInfo,
					Payload: domain.LogPayload{
						Message: line,
						Fields:  map[string]string{"op": "replace_last"},
					},
				})
				lastWasSeederStart = false
				lastSeeder = ""
				lastStep = ""
				continue
			}
		}

		if label, pct, tail, ok := parsePlainProgressLine(line); ok {
			_ = emit(domain.Event{
				Type:     domain.EventLog,
				StepID:   stepID,
				Source:   "php",
				Severity: domain.SeverityInfo,
				Payload: domain.LogPayload{
					Message: label,
					Fields: map[string]string{
						"kind":         "inline_progress",
						"op":           "replace_last_if_same",
						"progress_key": strings.ToLower(label),
						"label":        label,
						"pct":          fmt.Sprintf("%d", pct),
						"tail":         tail,
					},
				},
			})
			continue
		}

		evType := domain.EventLog
		sev := domain.SeverityInfo
		if l.stderr {
			evType = domain.EventWarning
			sev = domain.SeverityWarn
		}

		fields := map[string]string(nil)
		if line == lastPlainLine && stepID == lastPlainStepID && l.stderr == lastPlainWasStderr {
			fields = map[string]string{"op": "replace_last"}
		} else {
			lastPlainLine = line
			lastPlainStepID = stepID
			lastPlainWasStderr = l.stderr
		}

		_ = emit(domain.Event{
			Type:     evType,
			StepID:   stepID,
			Source:   "php",
			Severity: sev,
			Payload: domain.LogPayload{
				Message: line,
				Fields:  fields,
			},
		})
	}

	if err := cmd.Wait(); err != nil {
		tracker.FailRemaining()
		return err
	}
	if tracker.HasFailed() {
		tracker.FailRemaining()
		return fmt.Errorf("installation aborted due to failed step")
	}
	tracker.FinishRemaining()
	return nil
}

var seederStartRe = regexp.MustCompile(`^Running seeder:\s*([A-Za-z0-9_\\-]+)\.\.\.$`)
var seederDoneRe = regexp.MustCompile(`^✔\s*Seeder\s+([A-Za-z0-9_\\-]+)\s+completed\.\s*$`)

func parseSeederStartLine(line string) (seeder string, ok bool) {
	m := seederStartRe.FindStringSubmatch(strings.TrimSpace(line))
	if m == nil || len(m) < 2 {
		return "", false
	}
	return m[1], true
}

func parseSeederDoneLine(line string) (seeder string, ok bool) {
	m := seederDoneRe.FindStringSubmatch(strings.TrimSpace(line))
	if m == nil || len(m) < 2 {
		return "", false
	}
	return m[1], true
}

type stepTracker struct {
	emit func(domain.Event) bool

	current string

	done map[string]bool

	started map[string]bool
	failed  bool
}

func newStepTracker(emit func(domain.Event) bool) *stepTracker {
	return &stepTracker{
		emit:    emit,
		current: "download",
		done:    map[string]bool{},
		started: map[string]bool{},
	}
}

func (t *stepTracker) CurrentStepID() string {
	if t.current == "" {
		return "download"
	}
	return t.current
}

func (t *stepTracker) HasFailed() bool { return t.failed }

func (t *stepTracker) start(stepID, label string, index int) {
	if t.done[stepID] {
		return
	}
	if t.current == stepID {
		return
	}
	t.current = stepID
	t.started[stepID] = true
	_ = t.emit(domain.Event{
		Type:     domain.EventStepStart,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.StepStartPayload{
			Label: label,
			Index: index,
			Total: 8,
		},
	})
}

func (t *stepTracker) doneStep(stepID string, ok bool) {
	if t.done[stepID] {
		return
	}
	t.done[stepID] = true
	if !ok {
		t.failed = true
	}
	sev := domain.SeverityInfo
	if !ok {
		sev = domain.SeverityError
	}
	_ = t.emit(domain.Event{
		Type:     domain.EventStepDone,
		StepID:   stepID,
		Source:   "install",
		Severity: sev,
		Payload:  domain.StepDonePayload{OK: ok},
	})
}

func (t *stepTracker) OnLine(line string) {
	// Step 3 markers.
	if strings.Contains(line, "Downloading Evolution CMS") || strings.Contains(line, "Finding compatible Evolution CMS version") {
		t.start("download", "Step 3: Download Evolution CMS", 3)
	}
	if strings.Contains(line, "downloaded and extracted successfully") {
		t.doneStep("download", true)
		t.start("install", "Step 4: Install Evolution CMS", 4)
	}

	// Step 4 markers.
	if strings.Contains(line, "Setting up database") {
		t.start("install", "Step 4: Install Evolution CMS", 4)
	}
	if strings.Contains(line, "All seeders completed successfully") {
		t.doneStep("install", true)
		t.doneStep("presets", true)
	}
	// Install command now reports migrations and composer install as part of Step 4.
	if strings.Contains(line, "Running database migrations") || strings.Contains(line, "Running database seeders") {
		t.start("install", "Step 4: Install Evolution CMS", 4)
	}

	// Step 6 marker: deps update starts after install + presets.
	if strings.Contains(line, "Updating dependencies with Composer") {
		t.doneStep("install", true)
		t.doneStep("presets", true)
		t.start("dependencies", "Step 6: Install dependencies", 6)
	}
	if strings.Contains(line, "Dependencies updated successfully") || strings.Contains(line, "composer.json not found. Skipping dependency update") {
		t.doneStep("dependencies", true)
		t.start("finalize", "Step 7: Finalize installation", 7)
	}

	// Finalize marker.
	if strings.Contains(line, "Finalizing installation") {
		t.start("finalize", "Step 7: Finalize installation", 7)
	}
	if strings.Contains(line, "Installation finalized successfully") {
		t.doneStep("finalize", true)
	}

	// Generic failure hints.
	if strings.Contains(strings.ToLower(line), "failed to download evolution cms") {
		t.doneStep("download", false)
	}
	if strings.Contains(strings.ToLower(line), "migration failed") {
		t.doneStep("install", false)
	}
	if strings.Contains(strings.ToLower(line), "failed to install dependencies") {
		t.doneStep("install", false)
	}
	if strings.Contains(strings.ToLower(line), "failed to update dependencies") {
		t.doneStep("dependencies", false)
	}
}

func (t *stepTracker) FinishRemaining() {
	// Presets might not produce logs (it's a no-op by default), but should be considered done if install completed.
	if t.done["install"] {
		t.doneStep("presets", true)
	}
	// If PHP ran to completion, mark any started but not-done steps as OK (best-effort),
	// but never override an explicit failure.
	if t.failed {
		return
	}
	for _, id := range []string{"download", "install", "dependencies", "finalize"} {
		if t.started[id] && !t.done[id] {
			t.doneStep(id, true)
		}
	}
}

func (t *stepTracker) FailRemaining() {
	// Best-effort: mark any not-done steps as failed, so Quest track reflects the abort.
	for _, id := range []string{"download", "install", "presets", "dependencies", "finalize"} {
		if !t.done[id] {
			t.doneStep(id, false)
		}
	}
}

func askSelect(ctx context.Context, emit func(domain.Event) bool, actions <-chan domain.Action, stepID string, q domain.QuestionState) (string, bool) {
	if actions == nil {
		return "", false
	}
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.QuestionPayload{
			Question: q,
		},
	})
	for {
		select {
		case <-ctx.Done():
			return "", false
		case a := <-actions:
			if a.Type != domain.ActionAnswerSelect || a.QuestionID != q.ID {
				continue
			}
			return strings.TrimSpace(a.OptionID), true
		}
	}
}

func askInput(ctx context.Context, emit func(domain.Event) bool, actions <-chan domain.Action, stepID string, q domain.QuestionState) (string, bool) {
	if actions == nil {
		return "", false
	}
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.QuestionPayload{
			Question: q,
		},
	})
	for {
		select {
		case <-ctx.Done():
			return "", false
		case a := <-actions:
			if a.Type != domain.ActionAnswerInput || a.QuestionID != q.ID {
				continue
			}
			return a.Text, true
		}
	}
}

func (e *Engine) maybeOfferSelfUpdate(ctx context.Context, emit func(domain.Event) bool, actions <-chan domain.Action) bool {
	const stepID = "check_installer_update"

	current := strings.TrimSpace(e.opt.SelfVersion)
	if current == "" || strings.EqualFold(current, "dev") || strings.EqualFold(current, "unknown") {
		return false
	}
	curMaj, curMin, curPatch, ok := parseVersionForCompare(current)
	if !ok {
		return false
	}

	info, _, err := release.DetectHighestStable(ctx, "evolution-cms", "installer", release.DetectOptions{
		MaxPages: 3,
		CacheTTL: 12 * time.Hour,
	})
	if err != nil || info.HighestVersion == "" {
		// Fall back to GitHub's "latest" release endpoint (ignores pre-releases),
		// matching the PHP bootstrapper's self-update behavior.
		rel, err2 := github.FetchLatestRelease(ctx, "evolution-cms", "installer")
		if err2 != nil || strings.TrimSpace(rel.TagName) == "" {
			if err2 == nil {
				err2 = err
			}
			if err2 != nil {
				_ = emit(domain.Event{
					Type:     domain.EventWarning,
					StepID:   stepID,
					Source:   "install",
					Severity: domain.SeverityWarn,
					Payload: domain.LogPayload{
						Message: "Unable to check for installer updates.",
						Fields:  map[string]string{"error": err2.Error()},
					},
				})
			}
			return false
		}

		tagName := strings.TrimSpace(rel.TagName)
		highest := strings.TrimPrefix(strings.TrimPrefix(tagName, "v"), "V")
		if highest == "" {
			highest = tagName
		}
		tag := tagName
		if !strings.HasPrefix(strings.ToLower(tag), "v") && highest != "" {
			tag = "v" + highest
		}
		info = domain.ReleaseInfo{
			Repo:           "evolution-cms/installer",
			Tag:            tag,
			HighestVersion: highest,
		}
	}

	newMaj, newMin, newPatch, ok := parseVersionForCompare(info.HighestVersion)
	if !ok {
		return false
	}
	if cmpSemver(newMaj, newMin, newPatch, curMaj, curMin, curPatch) <= 0 {
		return false
	}

	tag := strings.TrimSpace(info.Tag)
	if tag == "" {
		tag = "v" + info.HighestVersion
	}

	cmdStr := "evo self-update"

	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: fmt.Sprintf("New installer version available: %s (current %s).", tag, current),
		},
	})
	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: "Recommended update command: " + cmdStr,
		},
	})

	if actions == nil {
		return false
	}

	updateEnabled := true
	reason := ""
	bootstrapper, bootErr := findPHPBootstrapperEntry()
	if bootErr != nil || strings.TrimSpace(bootstrapper) == "" {
		updateEnabled = false
		if bootErr != nil {
			reason = bootErr.Error()
		} else {
			reason = "PHP bootstrapper not found"
		}
	}

	choice, okSel := askSelect(ctx, emit, actions, stepID, domain.QuestionState{
		Active: true,
		ID:     "self_update",
		Kind:   domain.QuestionSelect,
		Prompt: "A new installer version is available. Update now?",
		Options: []domain.QuestionOption{
			{ID: "update", Label: "Update now (" + cmdStr + ")", Enabled: updateEnabled, Reason: reason},
			{ID: "skip", Label: "Continue without updating", Enabled: true},
		},
		Selected: 1,
	})
	if !okSel || choice != "update" {
		return false
	}

	_ = emit(domain.Event{
		Type:     domain.EventLog,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.LogPayload{
			Message: "Exiting installer and running: " + cmdStr,
		},
	})

	if !updateEnabled {
		return false
	}

	cmd := []string{bootstrapper, "self-update"}
	if runtime.GOOS == "windows" {
		cmd = []string{"php", bootstrapper, "self-update"}
	}
	_ = emit(domain.Event{
		Type:     domain.EventExecRequest,
		StepID:   stepID,
		Source:   "install",
		Severity: domain.SeverityInfo,
		Payload: domain.ExecRequestPayload{
			Command: cmd,
		},
	})

	return true
}

func parseVersionForCompare(s string) (major int, minor int, patch int, ok bool) {
	s = strings.TrimSpace(s)
	s = strings.TrimPrefix(s, "v")
	s = strings.TrimPrefix(s, "V")
	return parseSemverPrefix(s)
}

func cmpSemver(aMaj int, aMin int, aPatch int, bMaj int, bMin int, bPatch int) int {
	if aMaj != bMaj {
		if aMaj < bMaj {
			return -1
		}
		return 1
	}
	if aMin != bMin {
		if aMin < bMin {
			return -1
		}
		return 1
	}
	if aPatch != bPatch {
		if aPatch < bPatch {
			return -1
		}
		return 1
	}
	return 0
}

func validatePHPVersion(ctx context.Context) (string, bool, error) {
	// Must match installer/src/Validators/PhpValidator.php
	const minMajor, minMinor, minPatch = 8, 3, 0

	cmd := exec.CommandContext(ctx, "php", "-r", "echo PHP_VERSION;")
	out, err := cmd.Output()
	if err != nil {
		return "", false, err
	}
	v := strings.TrimSpace(string(out))
	maj, min, patch, ok := parseSemverPrefix(v)
	if !ok {
		return v, false, fmt.Errorf("unable to parse PHP_VERSION: %q", v)
	}
	if maj != minMajor {
		return v, maj > minMajor, nil
	}
	if min != minMinor {
		return v, min > minMinor, nil
	}
	return v, patch >= minPatch, nil
}

func parseSemverPrefix(s string) (int, int, int, bool) {
	// PHP_VERSION can contain extra suffixes; keep only X.Y.Z prefix.
	parts := strings.SplitN(strings.TrimSpace(s), ".", 4)
	if len(parts) < 3 {
		return 0, 0, 0, false
	}
	var maj, min, patch int
	if _, err := fmt.Sscanf(parts[0], "%d", &maj); err != nil {
		return 0, 0, 0, false
	}
	if _, err := fmt.Sscanf(parts[1], "%d", &min); err != nil {
		return 0, 0, 0, false
	}
	// patch may include suffix like "0RC1"
	p := parts[2]
	for i := 0; i < len(p); i++ {
		if p[i] < '0' || p[i] > '9' {
			p = p[:i]
			break
		}
	}
	if p == "" {
		return 0, 0, 0, false
	}
	if _, err := fmt.Sscanf(p, "%d", &patch); err != nil {
		return 0, 0, 0, false
	}
	return maj, min, patch, true
}

type dbConfig struct {
	Type     string `json:"type"`
	Host     string `json:"host,omitempty"`
	Port     int    `json:"port,omitempty"`
	Name     string `json:"name,omitempty"`
	User     string `json:"user,omitempty"`
	Password string `json:"password,omitempty"`
}

type dbTestResult struct {
	OK    bool   `json:"ok"`
	Error string `json:"error,omitempty"`
}

func defaultPort(dbType string) int {
	switch dbType {
	case "pgsql":
		return 5432
	case "sqlsrv":
		return 1433
	default:
		return 3306
	}
}

func dbDriverLabel(dbType string) string {
	switch strings.ToLower(strings.TrimSpace(dbType)) {
	case "mysql":
		return "MySQL/MariaDB"
	case "pgsql":
		return "PostgreSQL"
	case "sqlite":
		return "SQLite"
	case "sqlsrv":
		return "SQL Server"
	default:
		return dbType
	}
}

func enabledOptionIndexes(opts []domain.QuestionOption) []int {
	out := make([]int, 0, len(opts))
	for i, o := range opts {
		if o.Enabled {
			out = append(out, i)
		}
	}
	return out
}

func dbDriverQuestionOptions(status domain.SystemStatus) []domain.QuestionOption {
	// If we don't have system status yet, don't block the user.
	if len(status.Items) == 0 {
		return []domain.QuestionOption{
			{ID: "mysql", Label: "MySQL or MariaDB", Enabled: true},
			{ID: "pgsql", Label: "PostgreSQL", Enabled: true},
			{ID: "sqlite", Label: "SQLite", Enabled: true},
			{ID: "sqlsrv", Label: "SQL Server", Enabled: true},
		}
	}

	pdoLevel, ok := statusLevelForKey(status, "pdo")
	pdoOK := ok && pdoLevel == domain.StatusOK

	return []domain.QuestionOption{
		dbDriverOption(status, pdoOK, "mysql", "MySQL or MariaDB", "pdo_mysql"),
		dbDriverOption(status, pdoOK, "pgsql", "PostgreSQL", "pdo_pgsql"),
		dbDriverOption(status, pdoOK, "sqlite", "SQLite", "pdo_sqlite"),
		dbDriverOption(status, pdoOK, "sqlsrv", "SQL Server", "pdo_sqlsrv"),
	}
}

func dbDriverOption(status domain.SystemStatus, pdoOK bool, id string, label string, statusKey string) domain.QuestionOption {
	if !pdoOK {
		return domain.QuestionOption{ID: id, Label: label, Enabled: false, Reason: "Missing PHP extension: pdo"}
	}
	level, ok := statusLevelForKey(status, statusKey)
	if ok && level == domain.StatusOK {
		return domain.QuestionOption{ID: id, Label: label, Enabled: true}
	}
	return domain.QuestionOption{ID: id, Label: label, Enabled: false, Reason: "Missing PDO driver: " + statusKey}
}

func statusLevelForKey(status domain.SystemStatus, key string) (domain.StatusLevel, bool) {
	for _, it := range status.Items {
		if it.Key == key {
			return it.Level, true
		}
	}
	return domain.StatusError, false
}

const dbConnectionTestScript = `
$cfg = json_decode(base64_decode($argv[1] ?? ''), true);
if (!is_array($cfg)) { echo json_encode(["ok"=>false,"error"=>"Invalid config"]); exit(0); }
$type = $cfg["type"] ?? "mysql";
$driverMap = ["mysql"=>"mysql","pgsql"=>"pgsql","sqlite"=>"sqlite","sqlsrv"=>"sqlsrv"];
$required = $driverMap[$type] ?? null;
if ($required && !in_array($required, \PDO::getAvailableDrivers(), true)) {
  $driverName = match($type) { "mysql"=>"MySQL/MariaDB","pgsql"=>"PostgreSQL","sqlite"=>"SQLite","sqlsrv"=>"SQL Server", default => $type };
  $ext = match($type) { "sqlite"=>"pdo_sqlite","sqlsrv"=>"pdo_sqlsrv", default => "pdo_".$type };
  echo json_encode(["ok"=>false,"error"=>"PDO driver for {$driverName} is not installed. Please install PHP extension: {$ext}"]);
  exit(0);
}
try {
  $host = $cfg["host"] ?? "localhost";
  $port = (int)($cfg["port"] ?? 0);
  $name = $cfg["name"] ?? "";
  $user = $cfg["user"] ?? "";
  $pass = $cfg["password"] ?? "";
  $timeout = [\PDO::ATTR_TIMEOUT => 5];
  if ($type === "sqlite") {
    if ($name === "") { echo json_encode(["ok"=>false,"error"=>"SQLite database path is required."]); exit(0); }
    new \PDO("sqlite:".$name, null, null, $timeout);
    echo json_encode(["ok"=>true]); exit(0);
  }
  if ($type === "pgsql") {
    $maintenanceOk = false;
    $maintenanceErr = null;
    foreach (["postgres", "template1"] as $db) {
      try {
        $dsnMaint = $port > 0 ? "pgsql:host={$host};port={$port};dbname={$db}" : "pgsql:host={$host};dbname={$db}";
        new \PDO($dsnMaint, $user, $pass, $timeout);
        $maintenanceOk = true;
        break;
      } catch (\Throwable $e) {
        $maintenanceErr = $e;
      }
    }

    $targetOk = false;
    $targetErr = null;
    if ($name !== "") {
      try {
        $dsn = $port > 0 ? "pgsql:host={$host};port={$port};dbname={$name}" : "pgsql:host={$host};dbname={$name}";
        new \PDO($dsn, $user, $pass, $timeout);
        $targetOk = true;
      } catch (\Throwable $e) {
        $targetErr = $e;
      }
    }

    if (!$maintenanceOk && !$targetOk) {
      throw $targetErr ?? $maintenanceErr ?? new \Exception("PostgreSQL connection failed.");
    }

    echo json_encode(["ok"=>true]); exit(0);
  }

  $dsnNoDb = match($type) {
    "sqlsrv" => $port > 0 ? "sqlsrv:Server={$host},{$port}" : "sqlsrv:Server={$host}",
    default => "mysql:host={$host};port={$port};charset=utf8mb4",
  };
  new \PDO($dsnNoDb, $user, $pass, $timeout);
  if ($name !== "") {
    $dsn = match($type) {
      "sqlsrv" => ($port > 0 ? "sqlsrv:Server={$host},{$port};Database={$name}" : "sqlsrv:Server={$host};Database={$name}"),
      default => "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    };
    new \PDO($dsn, $user, $pass, $timeout);
  }
  echo json_encode(["ok"=>true]); exit(0);
} catch (\Throwable $e) {
  $msg = $e->getMessage();
  if (str_contains($msg, "could not find driver") || str_contains($msg, "driver not found")) {
    $driverName = match($type) { "mysql"=>"MySQL/MariaDB","pgsql"=>"PostgreSQL","sqlite"=>"SQLite","sqlsrv"=>"SQL Server", default => $type };
    $ext = match($type) { "sqlite"=>"pdo_sqlite","sqlsrv"=>"pdo_sqlsrv", default => "pdo_".$type };
    $msg = "PDO driver for {$driverName} is not installed. Please install PHP extension: {$ext}";
  }
  echo json_encode(["ok"=>false,"error"=>$msg]); exit(0);
}
`

func testDatabaseConnection(ctx context.Context, workDir string, cfg dbConfig) (bool, string, error) {
	raw, err := json.Marshal(cfg)
	if err != nil {
		return false, "", err
	}
	encoded := base64.StdEncoding.EncodeToString(raw)

	script := dbConnectionTestScript

	cmd := exec.CommandContext(ctx, "php", "-r", script, encoded)
	if strings.TrimSpace(workDir) != "" {
		cmd.Dir = workDir
	}
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	out, execErr := cmd.Output()
	if execErr != nil {
		if stderr.Len() > 0 {
			return false, "", fmt.Errorf("%w: %s", execErr, strings.TrimSpace(stderr.String()))
		}
		return false, "", execErr
	}

	var res dbTestResult
	if err := json.Unmarshal(out, &res); err != nil {
		return false, "", err
	}
	return res.OK, res.Error, nil
}

func languageQuestion() domain.QuestionState {
	options := []struct {
		id, label string
	}{
		{"en", "English"},
		{"uk", "Ukrainian"},
		{"az", "Azerbaijani"},
		{"be", "Belarusian"},
		{"bg", "Bulgarian"},
		{"cs", "Czech"},
		{"da", "Danish"},
		{"de", "German"},
		{"es", "Spanish"},
		{"fa", "Persian"},
		{"fi", "Finnish"},
		{"fr", "French"},
		{"he", "Hebrew"},
		{"it", "Italian"},
		{"ja", "Japanese"},
		{"nl", "Dutch"},
		{"nn", "Norwegian"},
		{"pl", "Polish"},
		{"pt", "Portuguese"},
		{"sv", "Swedish"},
		{"zh", "Chinese"},
		{"ru", "Russian"},
	}
	qOpts := make([]domain.QuestionOption, 0, len(options))
	selected := 0
	for i, o := range options {
		if o.id == "en" {
			selected = i
		}
		qOpts = append(qOpts, domain.QuestionOption{ID: o.id, Label: o.label, Enabled: true})
	}
	return domain.QuestionState{
		Active:   true,
		ID:       "language",
		Kind:     domain.QuestionSelect,
		Prompt:   "Which language do you want to use for installation?",
		Options:  qOpts,
		Selected: selected,
	}
}

func languageLabel(id string) string {
	switch strings.ToLower(strings.TrimSpace(id)) {
	case "en":
		return "English"
	case "uk":
		return "Ukrainian"
	case "az":
		return "Azerbaijani"
	case "be":
		return "Belarusian"
	case "bg":
		return "Bulgarian"
	case "cs":
		return "Czech"
	case "da":
		return "Danish"
	case "de":
		return "German"
	case "es":
		return "Spanish"
	case "fa":
		return "Persian"
	case "fi":
		return "Finnish"
	case "fr":
		return "French"
	case "he":
		return "Hebrew"
	case "it":
		return "Italian"
	case "ja":
		return "Japanese"
	case "nl":
		return "Dutch"
	case "nn":
		return "Norwegian"
	case "pl":
		return "Polish"
	case "pt":
		return "Portuguese"
	case "sv":
		return "Swedish"
	case "zh":
		return "Chinese"
	case "ru":
		return "Russian"
	default:
		return id
	}
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
	entry, err := findPHPSystemStatusEntry()
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
	// Backwards-compatible alias; keep for older callers.
	return findPHPSymfonyCLIEntry()
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

func findPHPSymfonyCLIEntry() (string, error) {
	// This entrypoint must be the internal Symfony Console runner (installer/bin/evo),
	// not the end-user bootstrapper (bin/evo). The bootstrapper proxies to the Go
	// binary and will fail when we pass PHP-only flags like --no-ansi/--no-interaction.

	candidates := []string{}
	if exe, err := os.Executable(); err == nil && exe != "" {
		exeDir := filepath.Dir(exe)
		base := filepath.Dir(exeDir)
		// Typical layout: <root>/bin/evo.bin and <root>/installer/bin/evo
		candidates = append(candidates,
			filepath.Join(base, "installer", "bin", "evo"),
			filepath.Join(exeDir, "installer", "bin", "evo"),
			filepath.Join(filepath.Dir(base), "installer", "bin", "evo"),
		)
	}

	// Repo-local fallbacks (when running from source checkout).
	candidates = append(candidates, filepath.Join("installer", "bin", "evo"))

	for _, p := range candidates {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		fi, err := os.Stat(p)
		if err != nil || fi.IsDir() {
			continue
		}
		if !looksLikePHPScript(p) {
			continue
		}
		if !looksLikeSymfonyEntry(p) {
			continue
		}

		abs, absErr := filepath.Abs(p)
		if absErr != nil {
			return p, nil
		}
		return abs, nil
	}

	return "", fmt.Errorf("unable to find Symfony PHP CLI entry (expected installer/bin/evo). Ensure the PHP installer package files are present next to the Go binary")
}

func findPHPSystemStatusEntry() (string, error) {
	// Prefer the Symfony entry (more complete), but fall back to the bootstrapper
	// which implements `system-status` without requiring Composer deps.
	if p, err := findPHPSymfonyCLIEntry(); err == nil {
		return p, nil
	}
	return findPHPBootstrapperEntry()
}

func findPHPBootstrapperEntry() (string, error) {
	candidates := []string{}

	// Prefer a sibling `evo` script next to the running executable (common install layout:
	// `evo` bootstrapper + `evo.bin` Go binary in the same directory).
	if exe, err := os.Executable(); err == nil && exe != "" {
		exeDir := filepath.Dir(exe)
		if exeDir != "" && exeDir != "." {
			candidates = append(candidates, filepath.Join(exeDir, "evo"))
		}
	}

	// Prefer the bootstrapper on PATH.
	if p, err := exec.LookPath("evo"); err == nil && p != "" {
		candidates = append(candidates, p)
	}

	// Repo-local fallback.
	candidates = append(candidates, filepath.Join("bin", "evo"))

	for _, p := range candidates {
		p = strings.TrimSpace(p)
		if p == "" {
			continue
		}
		fi, err := os.Stat(p)
		if err != nil || fi.IsDir() {
			continue
		}
		if !looksLikePHPScript(p) {
			continue
		}
		abs, absErr := filepath.Abs(p)
		if absErr != nil {
			return p, nil
		}
		return abs, nil
	}

	return "", fmt.Errorf("unable to find PHP bootstrapper entry (tried: %s)", strings.Join(candidates, ", "))
}

func looksLikeSymfonyEntry(path string) bool {
	// Fast path: expected location.
	p := filepath.ToSlash(path)
	if strings.HasSuffix(p, "/installer/bin/evo") {
		return true
	}

	f, err := os.Open(path)
	if err != nil {
		return false
	}
	defer f.Close()

	buf := make([]byte, 2048)
	n, _ := f.Read(buf)
	if n <= 0 {
		return false
	}
	head := string(buf[:n])
	return strings.Contains(head, "EvolutionCMS\\\\Installer\\\\Application") ||
		strings.Contains(head, "Internal PHP CLI entrypoint") ||
		strings.Contains(head, "Symfony Console")
}

func detectExistingEvoInstall(dir string) (bool, string) {
	dir = strings.TrimSpace(dir)
	if dir == "" {
		dir = "."
	}
	if fi, err := os.Stat(dir); err != nil || !fi.IsDir() {
		return false, ""
	}

	// Strong marker created by InstallCommand::finalizeInstallation().
	if fileExists(filepath.Join(dir, "core", ".install")) {
		return true, "core/.install"
	}

	// Heuristic: typical Evolution CMS layout in an already-populated directory.
	if dirExists(filepath.Join(dir, "core")) && dirExists(filepath.Join(dir, "manager")) && fileExists(filepath.Join(dir, "index.php")) {
		return true, "core/ + manager/ + index.php"
	}

	return false, ""
}

func fileExists(path string) bool {
	fi, err := os.Stat(path)
	return err == nil && fi.Mode().IsRegular()
}

func dirExists(path string) bool {
	fi, err := os.Stat(path)
	return err == nil && fi.IsDir()
}
