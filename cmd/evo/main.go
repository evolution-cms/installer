package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/exec"
	"os/signal"
	"regexp"
	"strconv"
	"strings"
	"syscall"

	"github.com/evolution-cms/installer/internal/domain"
	installengine "github.com/evolution-cms/installer/internal/engine/install"
	"github.com/evolution-cms/installer/internal/engine/mock"
	"github.com/evolution-cms/installer/internal/ui"
)

var (
	Version   = "dev"
	GitCommit = "none"
	BuildDate = "unknown"
)

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()

	os.Exit(run(ctx, os.Args[1:]))
}

func run(ctx context.Context, args []string) int {
	if len(args) == 0 {
		printUsage()
		return 2
	}

	cmd := strings.ToLower(strings.TrimSpace(args[0]))
	switch cmd {
	case "version", "--version", "-v":
		fmt.Printf("Evolution CMS Installer %s\n", Version)
		return 0
	case "-h", "--help", "help":
		printUsage()
		return 0
	case "install":
		if !ensureComposer2(ctx) {
			return 1
		}
		return runInstall(ctx, args[1:])
	case "doctor":
		if !ensureComposer2(ctx) {
			return 1
		}
		return runTUI(ctx, ui.ModeDoctor, nil)
	default:
		if !ensureComposer2(ctx) {
			return 1
		}
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n\n", args[0])
		printUsage()
		return 2
	}
}

var composerVersionMajorRe = regexp.MustCompile(`(?i)\bComposer version\s+(\d+)\.`)

func ensureComposer2(ctx context.Context) bool {
	out, err := exec.CommandContext(ctx, "composer", "--version").CombinedOutput()
	if err != nil {
		fmt.Fprintln(os.Stderr, "Composer 2.x is required.")
		fmt.Fprintln(os.Stderr, "Please upgrade Composer before continuing.")
		return false
	}

	m := composerVersionMajorRe.FindStringSubmatch(string(out))
	if len(m) < 2 {
		fmt.Fprintln(os.Stderr, "Composer 2.x is required.")
		fmt.Fprintln(os.Stderr, "Please upgrade Composer before continuing.")
		return false
	}

	major, err := strconv.Atoi(m[1])
	if err != nil || major < 2 {
		fmt.Fprintln(os.Stderr, "Composer 2.x is required.")
		fmt.Fprintln(os.Stderr, "Your system uses Composer 1.x which is incompatible with PHP 8.3.")
		fmt.Fprintln(os.Stderr, "Please upgrade Composer before continuing.")
		return false
	}

	return true
}

func runInstall(ctx context.Context, args []string) int {
	installDir, flagArgs, err := splitInstallArgs(args)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		printUsage()
		return 2
	}
	if strings.TrimSpace(installDir) == "" {
		installDir = "."
	}

	fs := flag.NewFlagSet("install", flag.ContinueOnError)
	fs.SetOutput(os.Stderr)

	force := fs.Bool("force", false, "Force installation even if directory exists")
	fs.BoolVar(force, "f", false, "Force installation even if directory exists")

	branch := fs.String("branch", "", "Install from specific Git branch instead of latest release")

	dbType := fs.String("db-type", "", "Database type (mysql, pgsql, sqlite, sqlsrv)")
	dbHost := fs.String("db-host", "", "Database host (default: localhost)")
	dbPort := fs.Int("db-port", 0, "Database port (default depends on driver)")
	dbName := fs.String("db-name", "", "Database name (or SQLite file path)")
	dbUser := fs.String("db-user", "", "Database username")
	dbPassword := fs.String("db-password", "", "Database password")

	adminUsername := fs.String("admin-username", "", "Admin username")
	adminEmail := fs.String("admin-email", "", "Admin email")
	adminPassword := fs.String("admin-password", "", "Admin password")
	adminDirectory := fs.String("admin-directory", "", "Admin directory (default: manager)")
	language := fs.String("language", "", "Installation language (e.g., en, uk)")

	if err := fs.Parse(flagArgs); err != nil {
		return 2
	}
	return runTUI(ctx, ui.ModeInstall, &installengine.Options{
		Force:          *force,
		Dir:            installDir,
		SelfVersion:    Version,
		Branch:         strings.TrimSpace(*branch),
		DBType:         strings.ToLower(strings.TrimSpace(*dbType)),
		DBHost:         strings.TrimSpace(*dbHost),
		DBPort:         *dbPort,
		DBName:         strings.TrimSpace(*dbName),
		DBUser:         strings.TrimSpace(*dbUser),
		DBPassword:     *dbPassword,
		AdminUsername:  strings.TrimSpace(*adminUsername),
		AdminEmail:     strings.TrimSpace(*adminEmail),
		AdminPassword:  *adminPassword,
		AdminDirectory: strings.TrimSpace(*adminDirectory),
		Language:       strings.ToLower(strings.TrimSpace(*language)),
	})
}

func splitInstallArgs(args []string) (installDir string, flagArgs []string, err error) {
	flagArgs = make([]string, 0, len(args))

	expectsValue := func(flag string) bool {
		switch flag {
		case "branch", "db-type", "db-host", "db-port", "db-name", "db-user", "db-password",
			"admin-username", "admin-email", "admin-password", "admin-directory", "language":
			return true
		default:
			return false
		}
	}

	trimFlagName := func(s string) string {
		s = strings.TrimLeft(s, "-")
		if i := strings.IndexByte(s, '='); i >= 0 {
			return s[:i]
		}
		return s
	}

	for i := 0; i < len(args); i++ {
		a := strings.TrimSpace(args[i])
		if a == "" {
			continue
		}
		if a == "--" {
			// Everything after "--" is positional.
			for j := i + 1; j < len(args); j++ {
				p := strings.TrimSpace(args[j])
				if p == "" {
					continue
				}
				if installDir == "" {
					installDir = p
					continue
				}
				return "", nil, fmt.Errorf("unexpected argument: %s", p)
			}
			break
		}

		if strings.HasPrefix(a, "-") {
			flagArgs = append(flagArgs, a)
			name := trimFlagName(a)
			if strings.Contains(a, "=") {
				continue
			}
			if name == "f" || name == "force" || !expectsValue(name) {
				continue
			}
			if i+1 >= len(args) {
				return "", nil, fmt.Errorf("missing value for flag: %s", a)
			}
			flagArgs = append(flagArgs, args[i+1])
			i++
			continue
		}

		if installDir == "" {
			installDir = a
			continue
		}
		return "", nil, fmt.Errorf("unexpected argument: %s", a)
	}
	return installDir, flagArgs, nil
}

func runTUI(ctx context.Context, mode ui.Mode, installOpt *installengine.Options) int {
	events := make(chan domain.Event, 256)
	actions := make(chan domain.Action, 16)
	var engine interface {
		Run(context.Context, chan<- domain.Event, <-chan domain.Action)
	}
	if mode == ui.ModeInstall {
		opt := installengine.Options{}
		if installOpt != nil {
			opt = *installOpt
		}
		engine = installengine.New(opt)
	} else {
		engine = mock.New()
	}
	engineCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	engine.Run(engineCtx, events, actions)

	if err := ui.RunWithCancel(ctx, mode, events, actions, ui.Meta{
		Version: Version,
		Tagline: "The world’s fastest CMS!",
	}, cancel); err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 1
	}
	return 0
}

func printUsage() {
	fmt.Println("Evolution CMS Installer TUI")
	fmt.Println("")
	fmt.Println("Usage:")
	fmt.Println("  evo install [dir] [flags]  Run TUI installer")
	fmt.Println("  evo doctor                 Run TUI doctor (mock engine)")
	fmt.Println("  evo version   Print version")
	fmt.Println("")
	fmt.Println("Common flags:")
	fmt.Println("  -f, --force                Force installation even if directory exists")
	fmt.Println("  --branch=<name>            Install from Git branch (e.g., 3.5.x)")
	fmt.Println("  --db-type=<driver>         mysql|pgsql|sqlite|sqlsrv")
	fmt.Println("  --db-name=<name|path>      Database name (or SQLite file path)")
	fmt.Println("  --admin-email=<email>      Admin email")
}
