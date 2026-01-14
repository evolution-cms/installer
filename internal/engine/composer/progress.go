package composer

import "regexp"

type Mapper struct {
	last int

	seenDownloading bool
	seenInstalling  bool
}

func NewMapper() *Mapper { return &Mapper{last: 0} }

var (
	errorHintRe = regexp.MustCompile(`(?i)\b(failed|fatal|error|could not|permission denied)\b`)

	rLoadingRepos    = regexp.MustCompile(`(?i)\bloading composer repositories\b`)
	rReadingComposer = regexp.MustCompile(`(?i)\breading .*composer\.json\b`)
	rUpdatingDeps    = regexp.MustCompile(`(?i)\bupdating dependencies\b`)
	rResolvingDeps   = regexp.MustCompile(`(?i)\bresolving dependencies\b`)
	rWritingLock     = regexp.MustCompile(`(?i)\bwriting lock file\b`)
	rPackageOps      = regexp.MustCompile(`(?i)\bpackage operations:\s*\d+\s+install(?:s)?\b`)
	rNoChanges       = regexp.MustCompile(`(?i)\bno changes required\b`)
	rGenAutoload     = regexp.MustCompile(`(?i)\bgenerating autoload files\b`)

	rDownloadingFromCache = regexp.MustCompile(`(?i)\bdownloading.*from cache\b`)
	rDownloading          = regexp.MustCompile(`(?i)\bdownloading\b`)
	rInstalling           = regexp.MustCompile(`(?i)\binstalling\b`)
)

// Observe consumes a single sanitized, trimmed line and returns an updated progress.
// ok=false means "no progress update".
func (m *Mapper) Observe(line string) (int, bool) {
	if line == "" {
		return 0, false
	}
	if errorHintRe.MatchString(line) {
		return 0, false
	}

	// Order matters.
	switch {
	case rLoadingRepos.MatchString(line):
		return m.advanceTo(5)
	case rReadingComposer.MatchString(line):
		return m.advanceTo(8)
	case rUpdatingDeps.MatchString(line):
		return m.advanceTo(15)
	case rResolvingDeps.MatchString(line):
		return m.advanceTo(20)
	case rWritingLock.MatchString(line):
		return m.advanceTo(35)
	case rPackageOps.MatchString(line):
		return m.advanceTo(45)
	case rDownloadingFromCache.MatchString(line):
		return m.observeDownloading(true)
	case rDownloading.MatchString(line):
		return m.observeDownloading(false)
	case rInstalling.MatchString(line):
		return m.observeInstalling()
	case rGenAutoload.MatchString(line):
		return m.advanceTo(90)
	case rNoChanges.MatchString(line):
		return m.advanceTo(95)
	default:
		return 0, false
	}
}

func (m *Mapper) observeDownloading(fromCache bool) (int, bool) {
	if !m.seenDownloading {
		m.seenDownloading = true
		if fromCache {
			// Cached downloads are usually fast; jump closer to the cap.
			return m.advanceTo(min(60, max(m.last, 55)))
		}
		return m.advanceTo(max(m.last, 30))
	}

	if m.last >= 60 {
		return 0, false
	}
	return m.advanceTo(min(60, m.last+1))
}

func (m *Mapper) observeInstalling() (int, bool) {
	if !m.seenInstalling {
		m.seenInstalling = true
		return m.advanceTo(max(m.last, 60))
	}

	if m.last >= 85 {
		return 0, false
	}
	return m.advanceTo(min(85, m.last+1))
}

func (m *Mapper) advanceTo(target int) (int, bool) {
	if target < 0 {
		target = 0
	}
	if target > 100 {
		target = 100
	}
	if target <= m.last {
		return 0, false
	}
	m.last = target
	return m.last, true
}

func min(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
