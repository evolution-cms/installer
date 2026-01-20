package install

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
)

type extrasListResponse struct {
	OK       *bool                  `json:"ok"`
	Error    string                 `json:"error"`
	Type     string                 `json:"type"`
	Packages []domain.ExtrasPackage `json:"packages"`
}

func parseExtrasListJSON(raw []byte) ([]domain.ExtrasPackage, error) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 {
		return nil, fmt.Errorf("empty extras list JSON")
	}

	var resp extrasListResponse
	if err := json.Unmarshal(raw, &resp); err == nil {
		if resp.OK != nil && !*resp.OK {
			if strings.TrimSpace(resp.Error) != "" {
				return nil, errors.New(resp.Error)
			}
			return nil, fmt.Errorf("extras list returned ok=false")
		}
		if len(resp.Packages) > 0 {
			return sanitizeExtrasPackages(resp.Packages), nil
		}
	}

	var flat []domain.ExtrasPackage
	if err := json.Unmarshal(raw, &flat); err == nil && len(flat) > 0 {
		return sanitizeExtrasPackages(flat), nil
	}

	return nil, fmt.Errorf("unable to parse extras list JSON")
}

func sanitizeExtrasPackages(pkgs []domain.ExtrasPackage) []domain.ExtrasPackage {
	out := make([]domain.ExtrasPackage, 0, len(pkgs))
	for _, p := range pkgs {
		name := strings.TrimSpace(p.Name)
		if name == "" {
			continue
		}
		p.Name = name
		p.Version = strings.TrimSpace(p.Version)
		p.Description = strings.TrimSpace(p.Description)
		p.DefaultInstallMode = strings.TrimSpace(p.DefaultInstallMode)
		p.DefaultBranch = strings.TrimSpace(p.DefaultBranch)
		if len(p.Versions) > 0 {
			seen := map[string]struct{}{}
			clean := make([]string, 0, len(p.Versions))
			for _, v := range p.Versions {
				v = strings.TrimSpace(v)
				if v == "" {
					continue
				}
				if _, ok := seen[v]; ok {
					continue
				}
				seen[v] = struct{}{}
				clean = append(clean, v)
			}
			p.Versions = clean
		}
		out = append(out, p)
	}
	return out
}

func fetchExtrasList(ctx context.Context, coreDir string, token string) ([]domain.ExtrasPackage, error) {
	coreDir = absDir(coreDir)
	artisan := filepath.Join(coreDir, "artisan")

	ctx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()

	args := []string{artisan, "extras", "--list", "--json", "--no-ansi", "--no-interaction"}
	cmd := exec.CommandContext(ctx, "php", args...)
	cmd.Dir = coreDir
	cmd.Env = append([]string(nil), os.Environ()...)
	cmd.Env = append(cmd.Env, "CI=1")
	if strings.TrimSpace(token) != "" {
		cmd.Env = append(cmd.Env, "GITHUB_PAT="+strings.TrimSpace(token))
	}

	out, err := cmd.CombinedOutput()
	pkgs, parseErr := parseExtrasListJSON(out)
	if parseErr == nil {
		return pkgs, nil
	}
	if err != nil {
		return nil, fmt.Errorf("extras list command failed: %w (%s)", err, strings.TrimSpace(string(out)))
	}
	return nil, parseErr
}

func checkExtrasPrereqs(ctx context.Context, workDir string) (string, string, error) {
	coreDir := absDir(filepath.Join(workDir, "core"))
	artisan := filepath.Join(coreDir, "artisan")
	if !fileExists(artisan) {
		return "", "", fmt.Errorf("missing %s", filepath.ToSlash(filepath.Join("core", "artisan")))
	}
	if _, err := exec.LookPath("php"); err != nil {
		return "", "", fmt.Errorf("php executable not found")
	}

	versionCtx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	cmd := exec.CommandContext(versionCtx, "php", artisan, "--version")
	cmd.Dir = coreDir
	if err := cmd.Run(); err != nil {
		// Fallback to php -v to ensure PHP runs at all.
		fallbackCtx, cancelFallback := context.WithTimeout(ctx, 5*time.Second)
		defer cancelFallback()
		if err2 := exec.CommandContext(fallbackCtx, "php", "-v").Run(); err2 != nil {
			return "", "", fmt.Errorf("unable to run php: %w", err2)
		}
		return coreDir, "Unable to run artisan --version; continuing with php -v.", nil
	}
	return coreDir, "", nil
}

func runArtisanCommand(ctx context.Context, coreDir string, token string, args []string) (string, error) {
	coreDir = absDir(coreDir)
	artisan := filepath.Join(coreDir, "artisan")
	fullArgs := append([]string{artisan}, args...)
	cmd := exec.CommandContext(ctx, "php", fullArgs...)
	cmd.Dir = coreDir
	cmd.Env = append([]string(nil), os.Environ()...)
	cmd.Env = append(cmd.Env, "CI=1")
	if strings.TrimSpace(token) != "" {
		cmd.Env = append(cmd.Env, "GITHUB_PAT="+strings.TrimSpace(token))
	}
	out, err := cmd.CombinedOutput()
	return string(out), err
}

func absDir(path string) string {
	if strings.TrimSpace(path) == "" {
		return path
	}
	if filepath.IsAbs(path) {
		return path
	}
	if abs, err := filepath.Abs(path); err == nil {
		return abs
	}
	return path
}
