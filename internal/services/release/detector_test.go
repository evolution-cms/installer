package release

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestCachePathIncludesRepoName(t *testing.T) {
	t.Parallel()

	p, err := cachePath("evolution-cms/evolution")
	if err != nil {
		t.Fatalf("cachePath error: %v", err)
	}
	base := filepath.Base(p)
	if !strings.HasPrefix(base, "release-") || !strings.HasSuffix(base, ".json") {
		t.Fatalf("unexpected cache file name: %q", base)
	}
	if !strings.Contains(base, "evolution-cms_evolution") {
		t.Fatalf("expected repo to be part of cache file name, got: %q", base)
	}
}
