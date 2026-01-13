package release

import (
	"errors"
	"regexp"
	"strconv"
	"strings"

	"github.com/evolution-cms/installer/internal/domain"
	"github.com/evolution-cms/installer/internal/services/github"
)

var versionRe = regexp.MustCompile(`(\d+)\.(\d+)\.(\d+)`)

type semver struct {
	major int
	minor int
	patch int
}

func parseSemver(s string) (semver, bool) {
	m := versionRe.FindStringSubmatch(s)
	if len(m) != 4 {
		return semver{}, false
	}
	maj, err1 := strconv.Atoi(m[1])
	min, err2 := strconv.Atoi(m[2])
	pat, err3 := strconv.Atoi(m[3])
	if err1 != nil || err2 != nil || err3 != nil {
		return semver{}, false
	}
	return semver{major: maj, minor: min, patch: pat}, true
}

func cmp(a semver, b semver) int {
	if a.major != b.major {
		if a.major < b.major {
			return -1
		}
		return 1
	}
	if a.minor != b.minor {
		if a.minor < b.minor {
			return -1
		}
		return 1
	}
	if a.patch != b.patch {
		if a.patch < b.patch {
			return -1
		}
		return 1
	}
	return 0
}

func SelectHighest(repo string, releases []github.GitHubRelease, includePrerelease bool) (domain.ReleaseInfo, error) {
	var (
		bestV   semver
		bestRaw string
		best    github.GitHubRelease
		found   bool
	)

	for _, r := range releases {
		if r.Draft || (!includePrerelease && r.Prerelease) {
			continue
		}

		tag := strings.TrimSpace(r.TagName)
		name := strings.TrimSpace(r.Name)

		v, ok := parseSemver(tag)
		raw := tag
		if !ok {
			v, ok = parseSemver(name)
			raw = name
		}
		if !ok {
			continue
		}

		if !found || cmp(v, bestV) > 0 {
			found = true
			bestV = v
			bestRaw = raw
			best = r
		}
	}

	if !found {
		return domain.ReleaseInfo{}, errors.New("no stable releases with semver tags found")
	}

	parsed, _ := parseSemver(bestRaw)
	highest := strconv.Itoa(parsed.major) + "." + strconv.Itoa(parsed.minor) + "." + strconv.Itoa(parsed.patch)

	return domain.ReleaseInfo{
		Repo:           repo,
		HighestVersion: highest,
		Tag:            best.TagName,
		Name:           best.Name,
		URL:            best.HTMLURL,
		IsPrerelease:   best.Prerelease,
	}, nil
}
