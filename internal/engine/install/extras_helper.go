package install

import (
	"context"
	_ "embed"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
)

//go:embed extras_helper.php
var extrasHelperPHP string

func runExtrasHelper(ctx context.Context, coreDir string, mode string, payload any) (string, error) {
	tmpDir, err := os.MkdirTemp("", "evo-installer-extras-*")
	if err != nil {
		return "", err
	}
	defer os.RemoveAll(tmpDir)

	scriptPath := filepath.Join(tmpDir, "extras_helper.php")
	if err := os.WriteFile(scriptPath, []byte(extrasHelperPHP), 0o600); err != nil {
		return "", err
	}

	payloadPath := filepath.Join(tmpDir, "payload.json")
	raw, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}
	if err := os.WriteFile(payloadPath, raw, 0o600); err != nil {
		return "", err
	}

	projectPath := filepath.Dir(absDir(coreDir))
	cmd := exec.CommandContext(ctx, "php", scriptPath, projectPath, mode, payloadPath)
	cmd.Dir = projectPath
	cmd.Env = append([]string(nil), os.Environ()...)
	cmd.Env = append(cmd.Env, "CI=1")
	out, err := cmd.CombinedOutput()
	return string(out), err
}
