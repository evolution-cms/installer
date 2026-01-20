package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"os/signal"
	"regexp"
	"strconv"
	"strings"
	"syscall"

	"github.com/evolution-cms/installer/internal/domain"
	installengine "github.com/evolution-cms/installer/internal/engine/install"
	"github.com/evolution-cms/installer/internal/logging"
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
	default:
		if !ensureComposer2(ctx) {
			return 1
		}
		fmt.Fprintf(os.Stderr, "Unknown command: %s\n\n", args[0])
		printUsage()
		return 2
	}
}

var composerVersionMajorRe = regexp.MustCompile(`(?i)\bComposer\s+(?:version\s+)?(\d+)\.`)

func ensureComposer2(ctx context.Context) bool {
	candidates := composerCandidates()
	var lastErr error
	var lastOut string
	var detectedMajor int
	var detectedBin string

	for _, bin := range candidates {
		outBytes, err := exec.CommandContext(ctx, bin, "--no-ansi", "--version").CombinedOutput()
		out := strings.TrimSpace(string(outBytes))

		m := composerVersionMajorRe.FindStringSubmatch(out)
		if len(m) >= 2 {
			major, parseErr := strconv.Atoi(m[1])
			if parseErr == nil {
				if major >= 2 {
					return true
				}
				detectedMajor = major
				detectedBin = bin
			}
		}

		if err == nil {
			lastErr = nil
			lastOut = out
			continue
		}

		lastErr = err
		lastOut = out

		// If the executable isn't found, try other common names/paths.
		if errors.Is(err, exec.ErrNotFound) {
			continue
		}
	}

	fmt.Fprintln(os.Stderr, "Composer 2.x is required.")
	if detectedMajor > 0 && detectedMajor < 2 {
		fmt.Fprintf(os.Stderr, "Detected Composer %d.x via %q.\n", detectedMajor, detectedBin)
		fmt.Fprintln(os.Stderr, "Your system uses Composer 1.x which is incompatible with PHP 8.3.")
		fmt.Fprintln(os.Stderr, "Please upgrade Composer before continuing.")
		return false
	}

	if lastErr != nil {
		// Help users who have Composer only as a shell alias/function (not an executable on PATH).
		if errors.Is(lastErr, exec.ErrNotFound) {
			fmt.Fprintln(os.Stderr, "Composer executable was not found in PATH.")
		} else {
			fmt.Fprintf(os.Stderr, "Could not run Composer: %v\n", lastErr)
		}
	}
	if lastOut != "" {
		fmt.Fprintf(os.Stderr, "Composer output: %s\n", firstLine(lastOut))
	}
	fmt.Fprintln(os.Stderr, "Please upgrade Composer before continuing.")
	fmt.Fprintln(os.Stderr, "Tip: if `composer` works in your shell but not here, it may be an alias/function; install the Composer binary or set EVO_COMPOSER_BIN.")
	return false
}

func composerCandidates() []string {
	var candidates []string
	seen := map[string]struct{}{}

	add := func(bin string) {
		bin = strings.TrimSpace(bin)
		if bin == "" {
			return
		}
		if _, ok := seen[bin]; ok {
			return
		}
		seen[bin] = struct{}{}
		candidates = append(candidates, bin)
	}

	if v := os.Getenv("EVO_COMPOSER_BIN"); strings.TrimSpace(v) != "" {
		add(v)
	}

	// Standard names that may be on PATH.
	add("composer")
	add("composer2")

	// Common system locations.
	add("/usr/local/bin/composer")
	add("/usr/bin/composer")
	add("/bin/composer")

	// Hosting panels (e.g. Hestia) sometimes provide Composer as a shell alias to a user-local path.
	if home, err := os.UserHomeDir(); err == nil && strings.TrimSpace(home) != "" {
		userLocal := []string{
			home + "/.composer/composer",
			home + "/.composer/vendor/bin/composer",
			home + "/bin/composer",
		}
		for _, p := range userLocal {
			if isExecutableFile(p) {
				add(p)
			}
		}
	}

	return candidates
}

func isExecutableFile(path string) bool {
	info, err := os.Stat(path)
	if err != nil {
		return false
	}
	if !info.Mode().IsRegular() {
		return false
	}
	return info.Mode().Perm()&fs.FileMode(0o111) != 0
}

func firstLine(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return strings.TrimSpace(s[:i])
	}
	return strings.TrimSpace(s)
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
	githubPat := fs.String("github-pat", "", "GitHub PAT token for API requests")
	githubPatAlt := fs.String("github_pat", "", "GitHub PAT token for API requests")
	extras := fs.String("extras", "", "Comma-separated extras to install (e.g., sTask@main,sSeo)")
	logToFile := fs.Bool("log", false, "Write installer log to file")
	cliMode := fs.Bool("cli", false, "Run in non-interactive CLI mode (no TUI)")
	quiet := fs.Bool("quiet", false, "Reduce CLI output (warnings/errors only)")
	composerClearCache := fs.Bool("composer-clear-cache", false, "Clear Composer cache before install")
	composerUpdate := fs.Bool("composer-update", false, "Use composer update instead of install during setup")

	if err := fs.Parse(flagArgs); err != nil {
		return 2
	}
	pat := strings.TrimSpace(*githubPat)
	if pat == "" {
		pat = strings.TrimSpace(*githubPatAlt)
	}

	opt := installengine.Options{
		Force:              *force,
		Dir:                installDir,
		SelfVersion:        Version,
		Branch:             strings.TrimSpace(*branch),
		ComposerClearCache: *composerClearCache,
		ComposerUpdate:     *composerUpdate,
		DBType:             strings.ToLower(strings.TrimSpace(*dbType)),
		DBHost:             strings.TrimSpace(*dbHost),
		DBPort:             *dbPort,
		DBName:             strings.TrimSpace(*dbName),
		DBUser:             strings.TrimSpace(*dbUser),
		DBPassword:         *dbPassword,
		AdminUsername:      strings.TrimSpace(*adminUsername),
		AdminEmail:         strings.TrimSpace(*adminEmail),
		AdminPassword:      *adminPassword,
		AdminDirectory:     strings.TrimSpace(*adminDirectory),
		Language:           strings.ToLower(strings.TrimSpace(*language)),
		GithubPat:          pat,
	}
	extrasSelections, err := parseExtrasSelections(*extras)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		return 2
	}
	opt.Extras = extrasSelections
	if *cliMode {
		if err := applyCLIDefaults(&opt); err != nil {
			fmt.Fprintln(os.Stderr, err)
			return 2
		}
	}
	return runInstaller(ctx, ui.ModeInstall, &opt, *logToFile, *cliMode, *quiet)
}

func parseExtrasSelections(raw string) ([]domain.ExtrasSelection, error) {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return nil, nil
	}
	parts := strings.Split(raw, ",")
	out := make([]domain.ExtrasSelection, 0, len(parts))
	for _, part := range parts {
		part = strings.TrimSpace(part)
		if part == "" {
			continue
		}
		name := part
		version := ""
		if strings.Contains(part, "@") {
			chunks := strings.SplitN(part, "@", 2)
			name = strings.TrimSpace(chunks[0])
			if len(chunks) > 1 {
				version = strings.TrimSpace(chunks[1])
			}
		}
		if name == "" {
			return nil, fmt.Errorf("invalid --extras value: %q", part)
		}
		out = append(out, domain.ExtrasSelection{Name: name, Version: version})
	}
	return out, nil
}

func splitInstallArgs(args []string) (installDir string, flagArgs []string, err error) {
	flagArgs = make([]string, 0, len(args))

	expectsValue := func(flag string) bool {
		switch flag {
		case "branch", "db-type", "db-host", "db-port", "db-name", "db-user", "db-password",
			"admin-username", "admin-email", "admin-password", "admin-directory", "language", "github-pat", "github_pat", "extras":
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

func runInstaller(ctx context.Context, mode ui.Mode, installOpt *installengine.Options, logAlways bool, cliMode bool, quiet bool) int {
	events := make(chan domain.Event, 256)
	actions := make(chan domain.Action, 16)
	var engine interface {
		Run(context.Context, chan<- domain.Event, <-chan domain.Action)
	}
	var opt installengine.Options
	if mode == ui.ModeInstall {
		if installOpt != nil {
			opt = *installOpt
		}
		engine = installengine.New(opt)
	}
	var logger *logging.EventLogger
	if mode == ui.ModeInstall {
		logger = logging.NewEventLogger(logging.Config{
			Always:         logAlways,
			InstallDir:     opt.Dir,
			Version:        Version,
			Mode:           string(mode),
			Force:          opt.Force,
			Branch:         opt.Branch,
			DBType:         opt.DBType,
			DBHost:         opt.DBHost,
			DBPort:         opt.DBPort,
			DBName:         opt.DBName,
			AdminDirectory: opt.AdminDirectory,
			Language:       opt.Language,
		})
	}
	engineCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	engine.Run(engineCtx, events, actions)

	var (
		postExec []string
		runErr   error
	)
	if cliMode {
		postExec, runErr = runCLI(ctx, events, actions, cancel, logger, quiet)
	} else {
		res, err := ui.RunWithCancel(ctx, mode, events, actions, ui.Meta{
			Version: Version,
			Tagline: "The world’s fastest CMS!",
			Branch:  strings.TrimSpace(opt.Branch),
		}, cancel, logger)
		runErr = err
		postExec = res.PostExecCommand
	}
	if runErr != nil && logger != nil {
		logger.MarkFailure()
	}
	if logger != nil {
		logRes, logErr := logger.Finalize()
		if logErr != nil {
			fmt.Fprintln(os.Stderr, logErr)
		}
		if logRes.Written {
			fmt.Fprintf(os.Stderr, "Installer log saved to %s\n", logRes.Path)
		}
	}
	if runErr != nil {
		fmt.Fprintln(os.Stderr, runErr)
		return 1
	}

	if len(postExec) > 0 {
		return runPostExec(ctx, postExec)
	}
	return 0
}

func runPostExec(ctx context.Context, argv []string) int {
	if len(argv) == 0 || strings.TrimSpace(argv[0]) == "" {
		return 0
	}

	run := func(cmd []string) error {
		c := exec.CommandContext(ctx, cmd[0], cmd[1:]...)
		c.Stdin = os.Stdin
		c.Stdout = os.Stdout
		c.Stderr = os.Stderr
		return c.Run()
	}

	if err := run(argv); err != nil {
		var exitErr *exec.ExitError
		if errors.As(err, &exitErr) {
			return exitErr.ExitCode()
		}

		// If we failed to execute a script directly (no shebang support / not executable),
		// retry via `php <script> ...`.
		if errors.Is(err, syscall.ENOEXEC) || errors.Is(err, syscall.EACCES) {
			phpArgv := append([]string{"php", argv[0]}, argv[1:]...)
			if err2 := run(phpArgv); err2 != nil {
				if errors.As(err2, &exitErr) {
					return exitErr.ExitCode()
				}
				fmt.Fprintln(os.Stderr, err2)
				return 1
			}
			return 0
		}

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
	fmt.Println("  evo version   Print version")
	fmt.Println("")
	fmt.Println("Common flags:")
	fmt.Println("  -f, --force                Force installation even if directory exists")
	fmt.Println("  --branch=<name>            Install from Git branch (e.g., main or master)")
	fmt.Println("  --db-type=<driver>         mysql|pgsql|sqlite|sqlsrv")
	fmt.Println("  --db-name=<name|path>      Database name (or SQLite file path)")
	fmt.Println("  --admin-email=<email>      Admin email")
	fmt.Println("  --log                      Always write installer log to log.md")
	fmt.Println("  --composer-clear-cache     Clear Composer cache before install")
	fmt.Println("  --composer-update          Use composer update instead of install during setup")
	fmt.Println("  --cli                      Run in non-interactive CLI mode (no TUI)")
	fmt.Println("  --quiet                    Reduce CLI output (warnings/errors only)")
}
