package release

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/evolution-cms/installer/internal/domain"
	"github.com/evolution-cms/installer/internal/services/github"
)

type DetectOptions struct {
	MaxPages          int
	CacheTTL          time.Duration
	IncludePrerelease bool
	OnPageFetched     func(page int)
}

func DetectHighestStable(ctx context.Context, owner string, repo string, opts DetectOptions) (domain.ReleaseInfo, bool, error) {
	if opts.MaxPages <= 0 {
		opts.MaxPages = 3
	}
	if opts.CacheTTL <= 0 {
		opts.CacheTTL = time.Hour
	}

	fullRepo := owner + "/" + repo

	cached, ok := readCache(fullRepo, opts.CacheTTL)
	if ok {
		cached.Source = "cache"
		return cached, true, nil
	}

	var all []github.GitHubRelease
	for page := 1; page <= opts.MaxPages; page++ {
		items, err := github.FetchReleasesPage(ctx, owner, repo, page)
		if err != nil {
			return domain.ReleaseInfo{}, false, err
		}
		if len(items) == 0 {
			break
		}
		all = append(all, items...)
		if opts.OnPageFetched != nil {
			opts.OnPageFetched(page)
		}
	}
	if len(all) == 0 {
		return domain.ReleaseInfo{}, false, errors.New("no releases returned")
	}

	info, err := SelectHighest(fullRepo, all, opts.IncludePrerelease)
	if err != nil {
		return domain.ReleaseInfo{}, false, err
	}
	info.FetchedAt = time.Now()
	info.Source = "github_api"

	_ = writeCache(info)
	return info, false, nil
}

type cacheFile struct {
	Release domain.ReleaseInfo `json:"release"`
}

func cachePath(repo string) (string, error) {
	dir, err := os.UserCacheDir()
	if err != nil || dir == "" {
		home, herr := os.UserHomeDir()
		if herr != nil {
			return "", herr
		}
		dir = filepath.Join(home, ".cache")
	}
	repo = strings.TrimSpace(repo)
	if repo == "" {
		repo = "unknown"
	}
	safe := strings.NewReplacer("/", "_", "\\", "_", " ", "_", ":", "_").Replace(repo)
	safe = strings.Trim(safe, "_")
	if safe == "" {
		safe = "unknown"
	}
	return filepath.Join(dir, "evo-installer", "release-"+safe+".json"), nil
}

func readCache(repo string, ttl time.Duration) (domain.ReleaseInfo, bool) {
	path, err := cachePath(repo)
	if err != nil {
		return domain.ReleaseInfo{}, false
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return domain.ReleaseInfo{}, false
	}

	var f cacheFile
	if err := json.Unmarshal(b, &f); err != nil {
		return domain.ReleaseInfo{}, false
	}
	if f.Release.Repo != repo {
		return domain.ReleaseInfo{}, false
	}
	if f.Release.FetchedAt.IsZero() {
		return domain.ReleaseInfo{}, false
	}
	if time.Since(f.Release.FetchedAt) > ttl {
		return domain.ReleaseInfo{}, false
	}
	return f.Release, true
}

func writeCache(info domain.ReleaseInfo) error {
	path, err := cachePath(info.Repo)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	f := cacheFile{Release: info}
	b, err := json.MarshalIndent(f, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, b, 0o644)
}
